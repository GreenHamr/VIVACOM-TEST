/**
 * IP Inventory REST API – backend с пълна имплементация на точка 3.
 * Конфиг: config.conf (host, port, db_path) или env IPINVENTORY_*.
 */

#include <cstdlib>
#include <fstream>
#include <iostream>
#include <sstream>
#include <string>

#include "httplib.h"
#include "storage.hpp"
#include "ip_validation.hpp"
#include "nlohmann/json.hpp"

using json = nlohmann::json;

namespace {

const char* getEnv(const char* name, const char* defaultVal) {
    const char* v = std::getenv(name);
    return v && v[0] ? v : defaultVal;
}

static std::string trim(const std::string& s) {
    auto start = s.find_first_not_of(" \t\r\n");
    if (start == std::string::npos) return std::string();
    auto end = s.find_last_not_of(" \t\r\n");
    return s.substr(start, end == std::string::npos ? std::string::npos : end - start + 1);
}

struct Config {
    std::string host;
    int port = 8888;
    ip_inventory::DbConfig db;
};
static void loadConfig(Config& c) {
    std::string path = getEnv("IPINVENTORY_CONFIG", "config.conf");
    std::ifstream f(path);
    if (!f.is_open()) return;
    std::string line;
    while (std::getline(f, line)) {
        line = trim(line);
        if (line.empty() || line[0] == '#') continue;
        std::size_t eq = line.find('=');
        if (eq == std::string::npos) continue;
        std::string key = trim(line.substr(0, eq));
        std::string val = trim(line.substr(eq + 1));
        if (key == "host" && !val.empty()) c.host = val;
        if (key == "port" && !val.empty()) { try { int p = std::stoi(val); if (p > 0 && p < 65536) c.port = p; } catch (...) {} }
        if (key == "db_type" && !val.empty()) c.db.dbType = val;
        if (key == "db_connection" && !val.empty()) c.db.dbConnection = val;
        if (key == "db_path" && !val.empty()) c.db.dbPath = val;
        if (key == "db_connection_string" && !val.empty()) c.db.connectionString = val;
        if (key == "db_host" && !val.empty()) c.db.dbHost = val;
        if (key == "db_port" && !val.empty()) c.db.dbPort = val;
        if (key == "db_name" && !val.empty()) c.db.dbName = val;
        if (key == "db_user" && !val.empty()) c.db.dbUser = val;
        if (key == "db_password" && !val.empty()) c.db.dbPassword = val;
    }
}

std::string getHost(const Config& c) {
    const char* e = getEnv("IPINVENTORY_HOST", nullptr);
    if (e && e[0]) return std::string(e);
    return c.host.empty() ? "127.0.0.1" : c.host;
}
int getPort(const Config& c) {
    const char* e = getEnv("IPINVENTORY_PORT", nullptr);
    if (e && e[0]) { try { int p = std::stoi(e); if (p > 0 && p < 65536) return p; } catch (...) {} }
    return c.port;
}
ip_inventory::DbConfig getDbConfig(const Config& c) {
    ip_inventory::DbConfig d = c.db;
    const char* e = getEnv("IPINVENTORY_DB_TYPE", nullptr);
    if (e && e[0]) d.dbType = e;
    e = getEnv("IPINVENTORY_DB_CONNECTION", nullptr);
    if (e && e[0]) d.dbConnection = e;
    e = getEnv("IPINVENTORY_DB", nullptr);
    if (e && e[0]) d.dbPath = e;
    if (d.dbType.empty()) d.dbType = "sqlite";
    if (d.dbConnection.empty()) d.dbConnection = "odbc";
    if (d.dbPath.empty() && d.dbType == "sqlite") d.dbPath = "ip_inventory.db";
    return d;
}

std::string jsonOk() {
    return R"({"statusCode":"0","statusMessage":"Successful operation. OK"})";
}
std::string jsonError(const std::string& code, const std::string& msg) {
    return json({{"statusCode", code}, {"statusMessage", msg}}).dump();
}
std::string jsonIpAddresses(const std::vector<ip_inventory::IpEntry>& list) {
    json arr = json::array();
    for (const auto& e : list)
        arr.push_back({{"ip", e.ip}, {"ipType", e.ipType}});
    return json({{"ipAddresses", arr}}).dump();
}

} // namespace

int main() {
    Config cfg;
    cfg.host = "127.0.0.1";
    cfg.port = 8888;
    cfg.db.dbType = "sqlite";
    cfg.db.dbConnection = "odbc";
    cfg.db.dbPath = "ip_inventory.db";
    loadConfig(cfg);

    std::string host = getHost(cfg);
    int port = getPort(cfg);
    ip_inventory::DbConfig dbConfig = getDbConfig(cfg);

    std::unique_ptr<ip_inventory::IStorage> storage = ip_inventory::createStorage(dbConfig);
    if (!storage) {
        if (dbConfig.dbType == "postgresql") {
            std::cerr << "PostgreSQL requested but not compiled. Install libpq-dev (Linux) or PostgreSQL client (Windows) and rebuild.\n";
            std::cerr << "Alternatively use db_type=sqlite in config.conf.\n";
        } else {
            std::cerr << "Unsupported db_type=" << dbConfig.dbType << " or db_connection=" << dbConfig.dbConnection << ". Use sqlite, or postgresql/mssql/oracle with odbc/ado_ole_db/orm.\n";
        }
        return 1;
    }
    std::string initErr;
    if (!storage->init(dbConfig, initErr)) {
        std::cerr << "Failed to init database: " << initErr << "\n";
        return 1;
    }

    httplib::Server svr;

    // --- POST /ip-inventory/ip-pool ---
    svr.Post("/ip-inventory/ip-pool", [&storage](const httplib::Request& req, httplib::Response& res) {
        try {
            json body = json::parse(req.body.empty() ? "{}" : req.body);
            if (!body.contains("ipAddresses") || !body["ipAddresses"].is_array()) {
                res.status = 400;
                res.set_content(jsonError("400", "Missing or invalid ipAddresses array"), "application/json");
                return;
            }
            std::vector<ip_inventory::IpEntry> entries;
            for (const auto& item : body["ipAddresses"]) {
                if (!item.contains("ip") || !item.contains("ipType")) {
                    res.status = 400;
                    res.set_content(jsonError("400", "Each item must have ip and ipType"), "application/json");
                    return;
                }
                std::string ip = item["ip"].get<std::string>();
                std::string ipType = item["ipType"].get<std::string>();
                if (ipType != "IPv4" && ipType != "IPv6") {
                    res.status = 400;
                    res.set_content(jsonError("400", "ipType must be IPv4 or IPv6"), "application/json");
                    return;
                }
                if (!ip_inventory::isValidIPWithType(ip, ipType)) {
                    res.status = 400;
                    res.set_content(jsonError("400", "Invalid IP address for type " + ipType), "application/json");
                    return;
                }
                entries.push_back({ip, ipType});
            }
            std::string err;
            if (!storage->addIps(entries, err)) {
                res.status = 500;
                res.set_content(jsonError("500", err), "application/json");
                return;
            }
            res.status = 200;
            res.set_content(jsonOk(), "application/json");
        } catch (const json::exception& e) {
            res.status = 400;
            res.set_content(jsonError("400", std::string("Invalid JSON: ") + e.what()), "application/json");
        }
    });

    // --- POST /ip-inventory/reserve-ip ---
    svr.Post("/ip-inventory/reserve-ip", [&storage](const httplib::Request& req, httplib::Response& res) {
        try {
            json body = json::parse(req.body.empty() ? "{}" : req.body);
            if (!body.contains("serviceId") || !body.contains("ipType")) {
                res.status = 400;
                res.set_content(jsonError("400", "Missing serviceId or ipType"), "application/json");
                return;
            }
            std::string serviceId = body["serviceId"].get<std::string>();
            std::string ipType = body["ipType"].get<std::string>();
            if (ipType != "IPv4" && ipType != "IPv6" && ipType != "Both") {
                res.status = 400;
                res.set_content(jsonError("400", "ipType must be IPv4, IPv6 or Both"), "application/json");
                return;
            }
            std::vector<ip_inventory::IpEntry> reserved;
            std::string err;
            if (!storage->reserveIps(serviceId, ipType, reserved, err)) {
                res.status = 500;
                res.set_content(jsonError("500", err), "application/json");
                return;
            }
            res.status = 200;
            res.set_content(jsonIpAddresses(reserved), "application/json");
        } catch (const json::exception& e) {
            res.status = 400;
            res.set_content(jsonError("400", std::string("Invalid JSON: ") + e.what()), "application/json");
        }
    });

    // --- POST /ip-inventory/assign-ip-serviceId ---
    svr.Post("/ip-inventory/assign-ip-serviceId", [&storage](const httplib::Request& req, httplib::Response& res) {
        try {
            json body = json::parse(req.body.empty() ? "{}" : req.body);
            if (!body.contains("serviceId") || !body.contains("ipAddresses")) {
                res.status = 400;
                res.set_content(jsonError("400", "Missing serviceId or ipAddresses"), "application/json");
                return;
            }
            std::string serviceId = body["serviceId"].get<std::string>();
            std::vector<std::string> ips;
            for (const auto& item : body["ipAddresses"]) {
                if (item.contains("ip")) ips.push_back(item["ip"].get<std::string>());
            }
            std::string err;
            if (!storage->assignIps(serviceId, ips, err)) {
                res.status = 400;
                res.set_content(jsonError("400", err), "application/json");
                return;
            }
            res.status = 200;
            res.set_content(jsonOk(), "application/json");
        } catch (const json::exception& e) {
            res.status = 400;
            res.set_content(jsonError("400", std::string("Invalid JSON: ") + e.what()), "application/json");
        }
    });

    // --- POST /ip-inventory/terminate-ip-serviceId ---
    svr.Post("/ip-inventory/terminate-ip-serviceId", [&storage](const httplib::Request& req, httplib::Response& res) {
        try {
            json body = json::parse(req.body.empty() ? "{}" : req.body);
            if (!body.contains("serviceId") || !body.contains("ipAddresses")) {
                res.status = 400;
                res.set_content(jsonError("400", "Missing serviceId or ipAddresses"), "application/json");
                return;
            }
            std::string serviceId = body["serviceId"].get<std::string>();
            std::vector<std::string> ips;
            for (const auto& item : body["ipAddresses"]) {
                if (item.contains("ip")) ips.push_back(item["ip"].get<std::string>());
            }
            std::string err;
            if (!storage->terminateIps(serviceId, ips, err)) {
                res.status = 400;
                res.set_content(jsonError("400", err), "application/json");
                return;
            }
            res.status = 200;
            res.set_content(jsonOk(), "application/json");
        } catch (const json::exception& e) {
            res.status = 400;
            res.set_content(jsonError("400", std::string("Invalid JSON: ") + e.what()), "application/json");
        }
    });

    // --- POST /ip-inventory/serviceId-change ---
    svr.Post("/ip-inventory/serviceId-change", [&storage](const httplib::Request& req, httplib::Response& res) {
        try {
            json body = json::parse(req.body.empty() ? "{}" : req.body);
            if (!body.contains("serviceIdOld") || !body.contains("serviceId")) {
                res.status = 400;
                res.set_content(jsonError("400", "Missing serviceIdOld or serviceId"), "application/json");
                return;
            }
            std::string oldId = body["serviceIdOld"].get<std::string>();
            std::string newId = body["serviceId"].get<std::string>();
            std::string err;
            if (!storage->changeServiceId(oldId, newId, err)) {
                res.status = 500;
                res.set_content(jsonError("500", err), "application/json");
                return;
            }
            res.status = 200;
            res.set_content(jsonOk(), "application/json");
        } catch (const json::exception& e) {
            res.status = 400;
            res.set_content(jsonError("400", std::string("Invalid JSON: ") + e.what()), "application/json");
        }
    });

    // --- GET /ip-inventory/serviceId?serviceId=xxx ---
    svr.Get("/ip-inventory/serviceId", [&storage](const httplib::Request& req, httplib::Response& res) {
        std::string serviceId = req.get_param_value("serviceId");
        if (serviceId.empty()) {
            res.status = 400;
            res.set_content(jsonError("400", "Missing serviceId query parameter"), "application/json");
            return;
        }
        std::vector<ip_inventory::IpEntry> list;
        std::string err;
        if (!storage->getByServiceId(serviceId, list, err)) {
            res.status = 500;
            res.set_content(jsonError("500", err), "application/json");
            return;
        }
        res.status = 200;
        res.set_content(jsonIpAddresses(list), "application/json");
    });

    svr.set_error_handler([](const httplib::Request& req, httplib::Response& res) {
        (void)req;
        if (res.status == 404)
            res.set_content(json({{"statusCode", "404"}, {"statusMessage", "Not Found"}}).dump(), "application/json");
    });

    std::cout << "IP Inventory backend listening on http://" << host << ":" << port << "\n";
    std::cout << "Database: type=" << dbConfig.dbType << " connection=" << dbConfig.dbConnection;
    if (!dbConfig.dbPath.empty()) std::cout << " path=" << dbConfig.dbPath;
    std::cout << "\n";

    if (!svr.listen(host, port)) {
        std::cerr << "Failed to listen on " << host << ":" << port << "\n";
        if (port < 1024)
            std::cerr << "On Linux, ports below 1024 require root. Use port >= 1024 (e.g. 8888).\n";
        return 1;
    }
    return 0;
}
