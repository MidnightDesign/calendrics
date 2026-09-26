#include <unicode/udatpg.h>
#include <unicode/ustring.h>

#include <iostream>
#include <string>

int main() {
    std::string locale;
    std::string skeleton;
    while (std::cin >> locale >> skeleton) {
        UErrorCode status = U_ZERO_ERROR;
        auto generator = udatpg_open(locale.c_str(), &status);
        UChar input[512];
        UChar pattern[512];
        int32_t length = 0;
        u_strFromUTF8(input, 512, &length, skeleton.c_str(), -1, &status);
        length = udatpg_getBestPatternWithOptions(
            generator, input, length, UDATPG_MATCH_HOUR_FIELD_LENGTH,
            pattern, 512, &status
        );
        char output[2048];
        u_strToUTF8(output, 2048, nullptr, pattern, length, &status);
        udatpg_close(generator);
        if (U_FAILURE(status)) {
            std::cerr << u_errorName(status) << std::endl;
            return 1;
        }
        std::cout << output << std::endl;
    }
}
