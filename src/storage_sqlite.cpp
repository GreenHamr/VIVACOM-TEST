#include "storage_sqlite.hpp"
#include <sqlite3.h>
#include <ctime>
#include <cstring>

namespace ip_inventory {

struct StorageSqlite::Impl {
    sqlite3* db = nullptr;
};

StorageSqlite::~StorageSqlite() {
    if (impl_ && impl_->db) {
        sqlite3_close(impl_->db);
        impl_->db = nullptr;
    }
    delete impl_;
    impl_ = nullptr;
}

bool StorageSqlite::init(const DbConfig& config, std::string& outError) {
    if (impl_) return impl_->db != nullptr;
    std::string path = config.dbPath.empty() ? "ip_inventory.db" : config.dbPath;
    impl_ = new Impl();
    if (sqlite3_open(path.c_str(), &impl_->db) != SQLITE_OK) {
        outError = impl_->db ? sqlite3_errmsg(impl_->db) : "sqlite3_open failed";
        if (impl_->db) sqlite3_close(impl_->db);
        impl_->db = nullptr;
        delete impl_;
        impl_ = nullptr;
        return false;
    }
    const char* sql =
        "CREATE TABLE IF NOT EXISTS ip_pool ("
        "id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "ip TEXT NOT NULL UNIQUE,"
        "ip_type TEXT NOT NULL CHECK(ip_type IN ('IPv4','IPv6')),"
        "status TEXT NOT NULL DEFAULT 'free' CHECK(status IN ('free','reserved','assigned')),"
        "service_id TEXT NULL,"
        "reserved_at TEXT NULL,"
        "assigned_at TEXT NULL,"
        "created_at TEXT NOT NULL DEFAULT (datetime('now')));"
        "CREATE INDEX IF NOT EXISTS idx_ip_pool_service_id ON ip_pool(service_id);"
        "CREATE INDEX IF NOT EXISTS idx_ip_pool_status_ip_type ON ip_pool(status, ip_type);";
    char* err = nullptr;
    if (sqlite3_exec(impl_->db, sql, nullptr, nullptr, &err) != SQLITE_OK) {
        outError = err ? err : "CREATE TABLE failed";
        if (err) sqlite3_free(err);
        sqlite3_close(impl_->db);
        impl_->db = nullptr;
        delete impl_;
        impl_ = nullptr;
        return false;
    }
    return true;
}

static std::string nowIso() {
    time_t t = time(nullptr);
    char buf[32];
    strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", gmtime(&t));
    return std::string(buf);
}

bool StorageSqlite::addIps(const std::vector<IpEntry>& entries, std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    sqlite3_stmt* stmt = nullptr;
    const char* sql = "INSERT OR IGNORE INTO ip_pool (ip, ip_type, status) VALUES (?, ?, 'free')";
    if (sqlite3_prepare_v2(impl_->db, sql, -1, &stmt, nullptr) != SQLITE_OK) {
        outError = "Failed to prepare statement";
        return false;
    }
    for (const auto& e : entries) {
        sqlite3_bind_text(stmt, 1, e.ip.c_str(), -1, SQLITE_TRANSIENT);
        sqlite3_bind_text(stmt, 2, e.ipType.c_str(), -1, SQLITE_TRANSIENT);
        int step = sqlite3_step(stmt);
        if (step != SQLITE_DONE) {
            outError = std::string("Insert failed: ") + (impl_->db ? sqlite3_errmsg(impl_->db) : "");
            sqlite3_finalize(stmt);
            return false;
        }
        sqlite3_reset(stmt);
    }
    sqlite3_finalize(stmt);
    return true;
}

bool StorageSqlite::reserveIps(const std::string& serviceId, const std::string& ipType,
                               std::vector<IpEntry>& outReserved, std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    outReserved.clear();
    std::vector<std::string> types;
    if (ipType == "IPv4") types.push_back("IPv4");
    else if (ipType == "IPv6") types.push_back("IPv6");
    else if (ipType == "Both") { types.push_back("IPv4"); types.push_back("IPv6"); }
    else { outError = "Invalid ipType"; return false; }

    for (const std::string& t : types) {
        std::string sql = "SELECT id, ip, ip_type FROM ip_pool WHERE status = 'free' AND ip_type = '" + t + "' ORDER BY id LIMIT 1";
        sqlite3_stmt* sel = nullptr;
        if (sqlite3_prepare_v2(impl_->db, sql.c_str(), -1, &sel, nullptr) != SQLITE_OK) {
            outError = "Failed to select free IPs";
            return false;
        }
        if (sqlite3_step(sel) == SQLITE_ROW) {
            int id = sqlite3_column_int(sel, 0);
            IpEntry e;
            e.ip = reinterpret_cast<const char*>(sqlite3_column_text(sel, 1));
            e.ipType = reinterpret_cast<const char*>(sqlite3_column_text(sel, 2));
            outReserved.push_back(e);
            sqlite3_finalize(sel);
            std::string now = nowIso();
            const char* upd = "UPDATE ip_pool SET status = 'reserved', service_id = ?, reserved_at = ? WHERE id = ?";
            sqlite3_stmt* ustmt = nullptr;
            if (sqlite3_prepare_v2(impl_->db, upd, -1, &ustmt, nullptr) != SQLITE_OK) {
                outError = "Failed to update";
                return false;
            }
            sqlite3_bind_text(ustmt, 1, serviceId.c_str(), -1, SQLITE_TRANSIENT);
            sqlite3_bind_text(ustmt, 2, now.c_str(), -1, SQLITE_TRANSIENT);
            sqlite3_bind_int(ustmt, 3, id);
            sqlite3_step(ustmt);
            sqlite3_finalize(ustmt);
        } else {
            sqlite3_finalize(sel);
        }
    }
    return true;
}

bool StorageSqlite::assignIps(const std::string& serviceId, const std::vector<std::string>& ips,
                              std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    std::string now = nowIso();
    const char* sql = "UPDATE ip_pool SET status = 'assigned', assigned_at = ? WHERE ip = ? AND service_id = ? AND status = 'reserved'";
    sqlite3_stmt* stmt = nullptr;
    if (sqlite3_prepare_v2(impl_->db, sql, -1, &stmt, nullptr) != SQLITE_OK) {
        outError = "Failed to prepare assign";
        return false;
    }
    for (const auto& ip : ips) {
        sqlite3_bind_text(stmt, 1, now.c_str(), -1, SQLITE_TRANSIENT);
        sqlite3_bind_text(stmt, 2, ip.c_str(), -1, SQLITE_TRANSIENT);
        sqlite3_bind_text(stmt, 3, serviceId.c_str(), -1, SQLITE_TRANSIENT);
        if (sqlite3_step(stmt) != SQLITE_DONE) {
            sqlite3_finalize(stmt);
            outError = "Assign failed: IP not reserved for this serviceId or invalid";
            return false;
        }
        sqlite3_reset(stmt);
    }
    sqlite3_finalize(stmt);
    return true;
}

bool StorageSqlite::terminateIps(const std::string& serviceId, const std::vector<std::string>& ips,
                                 std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    const char* sql = "UPDATE ip_pool SET status = 'free', service_id = NULL, reserved_at = NULL, assigned_at = NULL WHERE ip = ? AND service_id = ? AND status = 'assigned'";
    sqlite3_stmt* stmt = nullptr;
    if (sqlite3_prepare_v2(impl_->db, sql, -1, &stmt, nullptr) != SQLITE_OK) {
        outError = "Failed to prepare terminate";
        return false;
    }
    for (const auto& ip : ips) {
        sqlite3_bind_text(stmt, 1, ip.c_str(), -1, SQLITE_TRANSIENT);
        sqlite3_bind_text(stmt, 2, serviceId.c_str(), -1, SQLITE_TRANSIENT);
        if (sqlite3_step(stmt) != SQLITE_DONE) {
            sqlite3_finalize(stmt);
            outError = "Terminate failed: IP not assigned to this serviceId";
            return false;
        }
        sqlite3_reset(stmt);
    }
    sqlite3_finalize(stmt);
    return true;
}

bool StorageSqlite::changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew,
                                    std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    const char* sql = "UPDATE ip_pool SET service_id = ? WHERE service_id = ?";
    sqlite3_stmt* stmt = nullptr;
    if (sqlite3_prepare_v2(impl_->db, sql, -1, &stmt, nullptr) != SQLITE_OK) {
        outError = "Failed to prepare change";
        return false;
    }
    sqlite3_bind_text(stmt, 1, serviceIdNew.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, serviceIdOld.c_str(), -1, SQLITE_TRANSIENT);
    int r = sqlite3_step(stmt);
    sqlite3_finalize(stmt);
    if (r != SQLITE_DONE) { outError = "Change failed"; return false; }
    return true;
}

bool StorageSqlite::getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps,
                                   std::string& outError) {
    if (!impl_ || !impl_->db) { outError = "Database not initialized"; return false; }
    outIps.clear();
    const char* sql = "SELECT ip, ip_type FROM ip_pool WHERE service_id = ? AND status IN ('reserved','assigned')";
    sqlite3_stmt* stmt = nullptr;
    if (sqlite3_prepare_v2(impl_->db, sql, -1, &stmt, nullptr) != SQLITE_OK) {
        outError = "Failed to query";
        return false;
    }
    sqlite3_bind_text(stmt, 1, serviceId.c_str(), -1, SQLITE_TRANSIENT);
    while (sqlite3_step(stmt) == SQLITE_ROW) {
        IpEntry e;
        e.ip = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        e.ipType = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        outIps.push_back(e);
    }
    sqlite3_finalize(stmt);
    return true;
}

} // namespace ip_inventory
