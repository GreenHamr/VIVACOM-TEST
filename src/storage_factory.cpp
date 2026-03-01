#include "storage.hpp"
#include "storage_sqlite.hpp"
#ifdef IPINVENTORY_HAS_POSTGRESQL
#include "storage_postgresql.hpp"
#endif
#include "storage_odbc.hpp"
#include "storage_ado.hpp"
#include <algorithm>
#include <cctype>

namespace ip_inventory {

static std::string lower(const std::string& s) {
    std::string r = s;
    for (char& c : r) c = static_cast<char>(std::tolower(static_cast<unsigned char>(c)));
    return r;
}

std::unique_ptr<IStorage> createStorage(const DbConfig& config) {
    std::string dbType = lower(config.dbType);
    std::string dbConn = lower(config.dbConnection);

    if (dbType == "sqlite")
        return std::unique_ptr<IStorage>(new StorageSqlite());

#ifdef IPINVENTORY_HAS_POSTGRESQL
    if (dbType == "postgresql")
        return std::unique_ptr<IStorage>(new StoragePostgresql());
#endif

    if (dbType == "mssql" || dbType == "oracle") {
        if (dbConn == "odbc" || dbConn == "orm")
            return std::unique_ptr<IStorage>(new StorageOdbc());
        if (dbConn == "ado_ole_db" || dbConn == "ado")
            return std::unique_ptr<IStorage>(new StorageAdo());
    }

    return nullptr;
}

} // namespace ip_inventory
