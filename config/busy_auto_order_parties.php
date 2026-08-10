<?php
/**
 * Parties for which OMS auto-creates a same-day order when Busy invoices
 * remain unmapped (reverse of the normal order-then-dispatch flow).
 *
 * Matching uses normalized names (Pvt Ltd / Limited stripped, case-insensitive,
 * substring OK) against Busy Party Name / OMS parties master.
 */
return [
    'Sneha Minerals',
    'Sneha Minerlas', // Busy typo variant
    'Sidhi Vinayak Mines and Minerals',
    'Gargi Industries',
    'JLD Minerals Private Limited',
    'Daga Ceramics Pvt. Ltd.',
    'Suraj Ceramics Industries',
];
