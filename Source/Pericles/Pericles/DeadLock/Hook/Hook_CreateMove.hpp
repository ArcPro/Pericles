#pragma once

#include <cstdint>

class CCitadelInput;

void Hook_CreateMove(CCitadelInput* input, uint32_t splitScreenIndex, char argument);

extern void (*CreateMove_o)(CCitadelInput*, uint32_t, char);
