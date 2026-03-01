#include "storage_postgresql.hpp"
#include <libpq-fe.h>
#include <ctime>
#include <cstring>

namespace ip_inventory {

struct StoragePostgresql::Impl {
    PGconn* conn = nullptr;
};

StoragePostgresql::~StoragePostgresql() {
    if (impl_ && impl_->conn) {
        PQfinish(impl_->conn);
        impl_->conn = nullptr;
    }
    delete impl_;
    impl_ = nullptr;
}

static std::string nowIso() {
    time_t t = time(nullptr);
    char buf[32];
    strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", gmtime(&t));
    return std::string(buf);
}

static bool execOk(PGconn* conn, const char* sql, std::string& outError) {
    PGresult* res = PQexec(conn, sql);
    bool ok = (PQresultStatus(res) == PGRES_COMMAND_OK || PQresultStatus(res) == PGRES_TUPLES_OK);
    if (!ok && PQresultErrorMessage(res) && PQresultErrorMessage(res)[0])
        outError = PQresultErrorMessage(res);
    PQclear(res);
    return ok;
}

bool StoragePostgresql::init(const DbConfig& config, std::string& outError) {
    if (impl_ && impl_->conn) return true;
    std::string host = config.dbHost.empty() ? "localhost" : config.dbHost;
    std::string port = config.dbPort.empty() ? "5432" : config.dbPort;
    std::string dbname = config.dbName.empty() ? "postgres" : config.dbName;
    std::string user = config.dbUser;
    std::string password = config.dbPassword;

    std::string conninfo = "host=" + host + " port=" + port + " dbname=" + dbname;
    if (!user.empty()) conninfo += " user=" + user;
    if (!password.empty())
        conninfo += " password=" + password;
    else
        conninfo += " password=''";

    impl_ = new Impl();
    impl_->conn = PQconnectdb(conninfo.c_str());
    if (PQstatus(impl_->conn) != CONNECTION_OK) {
        outError = PQerrorMessage(impl_->conn) ? PQerrorMessage(impl_->conn) : "Connection failed";
        PQfinish(impl_->conn);
        impl_->conn = nullptr;
        delete impl_;
        impl_ = nullptr;
        return false;
    }

    const char* createSql =
        "CREATE TABLE IF NOT EXISTS ip_pool ("
        "id SERIAL PRIMARY KEY,"
        "ip VARCHAR(45) NOT NULL UNIQUE,"
        "ip_type VARCHAR(4) NOT NULL CHECK (ip_type IN ('IPv4', 'IPv6')),"
        "status VARCHAR(10) NOT NULL DEFAULT 'free' CHECK (status IN ('free', 'reserved', 'assigned')),"
        "service_id VARCHAR(255) NULL,"
        "reserved_at TIMESTAMP NULL,"
        "assigned_at TIMESTAMP NULL,"
        "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP);"
        "CREATE INDEX IF NOT EXISTS idx_ip_pool_service_id ON ip_pool (service_id);"
        "CREATE INDEX IF NOT EXISTS idx_ip_pool_status_ip_type ON ip_pool (status, ip_type);";
    if (!execOk(impl_->conn, createSql, outError)) {
        PQfinish(impl_->conn);
        impl_->conn = nullptr;
        delete impl_;
        impl_ = nullptr;
        return false;
    }
    return true;
}

bool StoragePostgresql::addIps(const std::vector<IpEntry>& entries, std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    for (const auto& e : entries) {
        const char* params[2] = { e.ip.c_str(), e.ipType.c_str() };
        PGresult* res = PQexecParams(impl_->conn,
            "INSERT INTO ip_pool (ip, ip_type, status) VALUES ($1::text, $2::text, 'free') ON CONFLICT (ip) DO NOTHING",
            2, nullptr, params, nullptr, nullptr, 0);
        if (PQresultStatus(res) != PGRES_COMMAND_OK) {
            outError = PQresultErrorMessage(res) ? PQresultErrorMessage(res) : "Insert failed";
            PQclear(res);
            return false;
        }
        PQclear(res);
    }
    return true;
}

bool StoragePostgresql::reserveIps(const std::string& serviceId, const std::string& ipType,
                                   std::vector<IpEntry>& outReserved, std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    outReserved.clear();
    std::vector<std::string> types;
    if (ipType == "IPv4") types.push_back("IPv4");
    else if (ipType == "IPv6") types.push_back("IPv6");
    else if (ipType == "Both") { types.push_back("IPv4"); types.push_back("IPv6"); }
    else { outError = "Invalid ipType"; return false; }

    std::string now = nowIso();
    for (const std::string& t : types) {
        const char* params[1] = { t.c_str() };
        PGresult* sel = PQexecParams(impl_->conn,
            "SELECT id, ip, ip_type FROM ip_pool WHERE status = 'free' AND ip_type = $1::text ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",
            1, nullptr, params, nullptr, nullptr, 0);
        if (PQresultStatus(sel) != PGRES_TUPLES_OK) {
            outError = PQresultErrorMessage(sel) ? PQresultErrorMessage(sel) : "Select failed";
            PQclear(sel);
            return false;
        }
        if (PQntuples(sel) > 0) {
            int id = atoi(PQgetvalue(sel, 0, 0));
            IpEntry e;
            e.ip = PQgetvalue(sel, 0, 1);
            e.ipType = PQgetvalue(sel, 0, 2);
            outReserved.push_back(e);
            PQclear(sel);

            char idBuf[32];
            snprintf(idBuf, sizeof(idBuf), "%d", id);
            const char* uparams[3] = { serviceId.c_str(), now.c_str(), idBuf };
            PGresult* upd = PQexecParams(impl_->conn,
                "UPDATE ip_pool SET status = 'reserved', service_id = $1::text, reserved_at = $2::timestamp WHERE id = $3::int",
                3, nullptr, uparams, nullptr, nullptr, 0);
            if (PQresultStatus(upd) != PGRES_COMMAND_OK) {
                outError = PQresultErrorMessage(upd) ? PQresultErrorMessage(upd) : "Update failed";
                PQclear(upd);
                return false;
            }
            PQclear(upd);
        } else {
            PQclear(sel);
        }
    }
    return true;
}

bool StoragePostgresql::assignIps(const std::string& serviceId, const std::vector<std::string>& ips,
                                  std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    std::string now = nowIso();
    for (const auto& ip : ips) {
        const char* params[3] = { now.c_str(), ip.c_str(), serviceId.c_str() };
        PGresult* res = PQexecParams(impl_->conn,
            "UPDATE ip_pool SET status = 'assigned', assigned_at = $1::timestamp WHERE ip = $2::text AND service_id = $3::text AND status = 'reserved'",
            3, nullptr, params, nullptr, nullptr, 0);
        if (PQresultStatus(res) != PGRES_COMMAND_OK) {
            outError = "Assign failed: IP not reserved for this serviceId or invalid";
            PQclear(res);
            return false;
        }
        if (PQcmdTuples(res) && atoi(PQcmdTuples(res)) == 0) {
            outError = "Assign failed: IP not reserved for this serviceId or invalid";
            PQclear(res);
            return false;
        }
        PQclear(res);
    }
    return true;
}

bool StoragePostgresql::terminateIps(const std::string& serviceId, const std::vector<std::string>& ips,
                                    std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    for (const auto& ip : ips) {
        const char* params[2] = { ip.c_str(), serviceId.c_str() };
        PGresult* res = PQexecParams(impl_->conn,
            "UPDATE ip_pool SET status = 'free', service_id = NULL, reserved_at = NULL, assigned_at = NULL WHERE ip = $1::text AND service_id = $2::text AND status = 'assigned'",
            2, nullptr, params, nullptr, nullptr, 0);
        if (PQresultStatus(res) != PGRES_COMMAND_OK) {
            outError = "Terminate failed: IP not assigned to this serviceId";
            PQclear(res);
            return false;
        }
        PQclear(res);
    }
    return true;
}

bool StoragePostgresql::changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew,
                                        std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    const char* params[2] = { serviceIdNew.c_str(), serviceIdOld.c_str() };
    PGresult* res = PQexecParams(impl_->conn,
        "UPDATE ip_pool SET service_id = $1::text WHERE service_id = $2::text",
        2, nullptr, params, nullptr, nullptr, 0);
    bool ok = (PQresultStatus(res) == PGRES_COMMAND_OK);
    if (!ok) outError = PQresultErrorMessage(res) ? PQresultErrorMessage(res) : "Change failed";
    PQclear(res);
    return ok;
}

bool StoragePostgresql::getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps,
                                       std::string& outError) {
    if (!impl_ || !impl_->conn) { outError = "Database not initialized"; return false; }
    outIps.clear();
    const char* params[1] = { serviceId.c_str() };
    PGresult* res = PQexecParams(impl_->conn,
        "SELECT ip, ip_type FROM ip_pool WHERE service_id = $1::text AND status IN ('reserved', 'assigned')",
        1, nullptr, params, nullptr, nullptr, 0);
    if (PQresultStatus(res) != PGRES_TUPLES_OK) {
        outError = PQresultErrorMessage(res) ? PQresultErrorMessage(res) : "Query failed";
        PQclear(res);
        return false;
    }
    int n = PQntuples(res);
    for (int i = 0; i < n; i++) {
        IpEntry e;
        e.ip = PQgetvalue(res, i, 0);
        e.ipType = PQgetvalue(res, i, 1);
        outIps.push_back(e);
    }
    PQclear(res);
    return true;
}

} // namespace ip_inventory
