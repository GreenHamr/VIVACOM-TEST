#include "storage_ado.hpp"

namespace ip_inventory {

bool StorageAdo::init(const DbConfig& config, std::string& outError) {
    (void)config;
#ifdef _WIN32
    outError = "ADO/OLE DB support is not yet implemented. Use db_connection=odbc for MSSQL on Windows.";
#else
    outError = "ADO/OLE DB is only available on Windows. Use db_connection=odbc for MSSQL.";
#endif
    return false;
}

bool StorageAdo::addIps(const std::vector<IpEntry>& entries, std::string& outError) {
    (void)entries;
    outError = "ADO not implemented";
    return false;
}

bool StorageAdo::reserveIps(const std::string& serviceId, const std::string& ipType,
                            std::vector<IpEntry>& outReserved, std::string& outError) {
    (void)serviceId; (void)ipType; outReserved.clear();
    outError = "ADO not implemented";
    return false;
}

bool StorageAdo::assignIps(const std::string& serviceId, const std::vector<std::string>& ips, std::string& outError) {
    (void)serviceId; (void)ips;
    outError = "ADO not implemented";
    return false;
}

bool StorageAdo::terminateIps(const std::string& serviceId, const std::vector<std::string>& ips, std::string& outError) {
    (void)serviceId; (void)ips;
    outError = "ADO not implemented";
    return false;
}

bool StorageAdo::changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew, std::string& outError) {
    (void)serviceIdOld; (void)serviceIdNew;
    outError = "ADO not implemented";
    return false;
}

bool StorageAdo::getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps, std::string& outError) {
    (void)serviceId; outIps.clear();
    outError = "ADO not implemented";
    return false;
}

} // namespace ip_inventory
