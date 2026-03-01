#include "ip_validation.hpp"
#include <cctype>
#include <sstream>

namespace ip_inventory {

static bool isIPv4Segment(const std::string& s) {
    if (s.empty() || s.size() > 3) return false;
    for (char c : s) if (!std::isdigit(static_cast<unsigned char>(c))) return false;
    int n = 0;
    try { n = std::stoi(s); } catch (...) { return false; }
    return n >= 0 && n <= 255;
}

bool isValidIPv4(const std::string& ip) {
    if (ip.empty() || ip.size() > 15) return false;
    std::istringstream iss(ip);
    std::string seg;
    int count = 0;
    while (std::getline(iss, seg, '.')) {
        if (!isIPv4Segment(seg)) return false;
        ++count;
    }
    return count == 4;
}

static bool isHexDigit(char c) {
    return std::isdigit(static_cast<unsigned char>(c)) ||
           (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

static bool isIPv6Segment(const std::string& s) {
    if (s.empty() || s.size() > 4) return false;
    for (char c : s) if (!isHexDigit(c)) return false;
    return true;
}

bool isValidIPv6(const std::string& ip) {
    if (ip.empty() || ip.size() > 45) return false;
    std::istringstream iss(ip);
    std::string seg;
    int count = 0;
    int emptyCount = 0;
    while (std::getline(iss, seg, ':')) {
        if (seg.empty()) {
            ++emptyCount;
            if (emptyCount > 2) return false;
            continue;
        }
        if (!isIPv6Segment(seg)) return false;
        ++count;
    }
    return count >= 1 && count <= 8 && emptyCount <= 2;
}

bool isValidIPWithType(const std::string& ip, const std::string& ipType) {
    if (ipType == "IPv4") return isValidIPv4(ip);
    if (ipType == "IPv6") return isValidIPv6(ip);
    return false;
}

} // namespace ip_inventory
