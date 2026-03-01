#ifndef STORAGE_POSTGRESQL_HPP
#define STORAGE_POSTGRESQL_HPP

#include "storage.hpp"

namespace ip_inventory {

class StoragePostgresql : public IStorage {
public:
    StoragePostgresql() = default;
    ~StoragePostgresql() override;

    bool init(const DbConfig& config, std::string& outError) override;
    bool addIps(const std::vector<IpEntry>& entries, std::string& outError) override;
    bool reserveIps(const std::string& serviceId, const std::string& ipType,
                    std::vector<IpEntry>& outReserved, std::string& outError) override;
    bool assignIps(const std::string& serviceId, const std::vector<std::string>& ips,
                   std::string& outError) override;
    bool terminateIps(const std::string& serviceId, const std::vector<std::string>& ips,
                      std::string& outError) override;
    bool changeServiceId(const std::string& serviceIdOld, const std::string& serviceIdNew,
                        std::string& outError) override;
    bool getByServiceId(const std::string& serviceId, std::vector<IpEntry>& outIps,
                        std::string& outError) override;

private:
    struct Impl;
    Impl* impl_ = nullptr;
};

} // namespace ip_inventory

#endif
