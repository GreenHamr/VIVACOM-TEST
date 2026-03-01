#ifndef IP_VALIDATION_HPP
#define IP_VALIDATION_HPP

#include <string>

namespace ip_inventory {

/** Връща true ако низът е валиден IPv4 (напр. 192.168.1.1). */
bool isValidIPv4(const std::string& ip);

/** Връща true ако низът е валиден IPv6 (опростена проверка: допустими hex цифри, ':', максимум 8 групи). */
bool isValidIPv6(const std::string& ip);

/** Връща true ако ip отговаря на дадения ipType ("IPv4" или "IPv6"). */
bool isValidIPWithType(const std::string& ip, const std::string& ipType);

} // namespace ip_inventory

#endif
