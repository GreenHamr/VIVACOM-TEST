#include "storage_odbc.hpp"

namespace ip_inventory {

struct StorageOdbc::Impl {
    bool dummy = false;
};

StorageOdbc::~StorageOdbc() {
    delete impl_;
    impl_ = nullptr;
}

bool StorageOdbc::init(const DbConfig& config, std::string& outError) {
    (void)config;
    outError = "ODBC support not compiled. Set CMake option IPINVENTORY_USE_ODBC=ON and install unixODBC (Linux) or use Windows SDK.";
    return false;
}

bool StorageOdbc::addIps(const std::vector<IpEntry>& entries, std::string& outError) {
    (void)entries;
    outError = "ODBC not compiled";
    return false;
}

bool StorageOdbc::reserveIps(const std::string& serviceId, const std::string& ipType,
                             std::vector<IpEntry>& outReserved, std::string& outError) {
    (void)serviceId; (void)ipType; outReserved.clear();
    outError = "ODBC not compiled";
    return false;
}

bool StorageOdbc::assignIps(const std::string& serviceId, const std::vector<std::string>& ips, std::string& outError) {
    (void)serviceId; (void)ips;
    outError = "ODBC not compiled";
    return false;
}

bool StorageOdbc::terminateIps(const std::string& serviceId, const std::vector<std::string>& ips, std::string& outError) {
    (void)serviceId; (void)ips;
    outError = "ODBC not compiled";
    return false;
}

bool StorageOdbc::changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew, std::string& outError) {
    (void)serviceIdOld; (void)serviceIdNew;
    outError = "ODBC not compiled";
    return false;
}

bool StorageOdbc::getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps, std::string& outError) {
    (void)serviceId; outIps.clear();
    outError = "ODBC not compiled";
    return false;
}

} // namespace ip_inventory
