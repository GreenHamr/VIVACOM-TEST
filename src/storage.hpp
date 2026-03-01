#ifndef STORAGE_HPP
#define STORAGE_HPP

#include <memory>
#include <string>
#include <vector>

namespace ip_inventory {

struct IpEntry {
    std::string ip;
    std::string ipType;
};

/** Конфигурация на БД – четене от config.conf (db_type, db_connection, db_path, db_*). */
struct DbConfig {
    std::string dbType;       /**< sqlite | postgresql | mssql | oracle */
    std::string dbConnection; /**< odbc | ado_ole_db | orm (за postgresql/mssql/oracle) */
    std::string dbPath;       /**< за sqlite: път до файл */
    std::string connectionString; /**< за ODBC: пълен connection string */
    std::string dbHost;
    std::string dbPort;
    std::string dbName;
    std::string dbUser;
    std::string dbPassword;
};

/** Абстрактен интерфейс за съхранение на IP pool (поддръжка на SQLite, ODBC, ADO, ORM). */
class IStorage {
public:
    virtual ~IStorage() = default;

    virtual bool init(const DbConfig& config, std::string& outError) = 0;

    virtual bool addIps(const std::vector<IpEntry>& entries, std::string& outError) = 0;
    virtual bool reserveIps(const std::string& serviceId, const std::string& ipType,
                            std::vector<IpEntry>& outReserved, std::string& outError) = 0;
    virtual bool assignIps(const std::string& serviceId, const std::vector<std::string>& ips,
                           std::string& outError) = 0;
    virtual bool terminateIps(const std::string& serviceId, const std::vector<std::string>& ips,
                              std::string& outError) = 0;
    virtual bool changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew,
                                 std::string& outError) = 0;
    virtual bool getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps,
                                std::string& outError) = 0;
};

/** Фабрика: създава подходяща имплементация според db_type и db_connection. */
std::unique_ptr<IStorage> createStorage(const DbConfig& config);

} // namespace ip_inventory

#endif
