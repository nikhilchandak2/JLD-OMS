<?php
/**
 * Parties for which OMS auto-creates a same-day order when Busy invoices
 * remain unmapped (reverse of the normal order-then-dispatch flow).
 *
 * Keys = Busy / OMS party name patterns (normalized match).
 * Values = preferred OMS company name (used when the party has no order history;
 *          when history exists, the company's past orders win).
 */
return [
    'Sneha Minerals' => 'Jaichand Lal Daga',
    'Sneha Minerlas' => 'Jaichand Lal Daga', // Busy typo
    'Sidhi Vinayak Mines and Minerals' => 'Jaichand Lal Daga',
    'Gargi Industries' => 'Jaichand Lal Daga',
    'JLD Minerals Private Limited' => 'JLD Minerals Private Limited',
    'Daga Ceramics Pvt. Ltd.' => 'Jaichand Lal Daga',
    'Suraj Ceramics Industries' => 'Jaichand Lal Daga',
];
