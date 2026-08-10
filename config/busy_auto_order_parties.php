<?php
/**
 * Allowlisted parties for Busy reverse auto-orders.
 *
 * Keys   = Busy / OMS party name patterns
 * Values = OMS company order_prefix (preferred) or full company name
 *
 * All of these trade under Jaichand Lal Daga (prefix JLD), NOT JL Daga Mines (JLDMM).
 */
return [
    'Sneha Minerals' => 'JLD',
    'Sneha Minerlas' => 'JLD', // Busy typo
    'Sidhi Vinayak Mines and Minerals' => 'JLD',
    'Gargi Industries' => 'JLD',
    'JLD Minerals Private Limited' => 'JLD',
    'Daga Ceramics Pvt. Ltd.' => 'JLD',
    'Suraj Ceramics Industries' => 'JLD',
];
