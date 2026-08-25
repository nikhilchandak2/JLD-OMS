<?php

namespace App\Models;

class Party
{
    public int $id = 0;
    public string $name = '';
    public string $contactPerson = '';
    public string $gstNumber = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public bool $isActive = true;
    public ?string $region = null;
    public ?string $productCategory = null;
    public ?string $productionCapacity = null;
    public ?string $factoryLocations = null;
    public ?float $creditLimit = null;
    public ?int $paymentTermsDays = null;
    public ?string $technicalNotes = null;
    public ?string $productsIntroduced = null;
    public ?string $monthlyConsumption = null;
    public ?int $yearOfAssociation = null;
    public ?string $orderFrequency = null;
    public ?string $lastOrderDate = null;
    public ?string $lastVisitDate = null;
    public ?string $paymentTrack = null;
    public ?string $targetVolume = null;
    public ?string $nextFollowupDate = null;
    public ?int $assignedSalesOwner = null;
    public ?int $numberOfPlants = null;
    public ?string $generalNotes = null;
    public ?string $funnelStage = null;
    public ?string $industryType = null;
    public ?string $tilesSubtype = null;
    public ?float $monthlyConsumptionTon = null;
    public ?float $avgPricePerTon = null;
    public ?string $currentSupplierDetails = null;
    /** @deprecated TASK 4: superseded by crm_contacts.relationship_strength. Do not drop yet. */
    public ?int $relationWithPurchase = null;
    /** @deprecated TASK 4: superseded by crm_contacts.relationship_strength. Do not drop yet. */
    public ?int $relationWithInternalTeam = null;
    /** @deprecated TASK 4: superseded by structured pipeline + contact influence. Do not drop yet. */
    public ?int $probabilityOfConversion = null;
    public ?string $visitDescription = null;
    public ?string $followupNotes = null;
    /** @var array<int, array{product: string, price: string|float|null}>|null */
    public ?array $visitSamplesProvided = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
    }

    public function fill(array $data): void
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->name = $data['name'] ?? '';
        $this->contactPerson = $data['contact_person'] ?? '';
        $this->gstNumber = self::normalizeGstNumber($data['gst_number'] ?? '');
        $this->phone = $data['phone'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->address = $data['address'] ?? '';
        $this->isActive = (bool)($data['is_active'] ?? true);
        $this->region = $data['region'] ?? null;
        $this->productCategory = $data['product_category'] ?? null;
        $this->productionCapacity = $data['production_capacity'] ?? null;
        $this->factoryLocations = $data['factory_locations'] ?? null;
        $this->creditLimit = isset($data['credit_limit']) ? (float)$data['credit_limit'] : null;
        $this->paymentTermsDays = isset($data['payment_terms_days']) ? (int)$data['payment_terms_days'] : null;
        $this->technicalNotes = $data['technical_notes'] ?? null;
        $this->productsIntroduced = $data['products_introduced'] ?? null;
        $this->monthlyConsumption = $data['monthly_consumption'] ?? null;
        $this->yearOfAssociation = isset($data['year_of_association']) ? (int)$data['year_of_association'] : null;
        $this->orderFrequency = $data['order_frequency'] ?? null;
        $this->lastOrderDate = $data['last_order_date'] ?? null;
        $this->lastVisitDate = $data['last_visit_date'] ?? null;
        $this->paymentTrack = $data['payment_track'] ?? null;
        $this->targetVolume = $data['target_volume'] ?? null;
        $this->nextFollowupDate = $data['next_followup_date'] ?? null;
        $this->assignedSalesOwner = isset($data['assigned_sales_owner']) ? (int)$data['assigned_sales_owner'] : null;
        $this->numberOfPlants = isset($data['number_of_plants']) ? (int)$data['number_of_plants'] : null;
        $this->generalNotes = $data['general_notes'] ?? null;
        $this->funnelStage = $data['funnel_stage'] ?? null;
        $this->industryType = $data['industry_type'] ?? null;
        $this->tilesSubtype = $data['tiles_subtype'] ?? null;
        $this->monthlyConsumptionTon = isset($data['monthly_consumption_ton']) ? (float)$data['monthly_consumption_ton'] : null;
        $this->avgPricePerTon = isset($data['avg_price_per_ton']) ? (float)$data['avg_price_per_ton'] : null;
        $this->currentSupplierDetails = $data['current_supplier_details'] ?? null;
        $this->relationWithPurchase = isset($data['relation_with_purchase']) ? (int)$data['relation_with_purchase'] : null;
        $this->relationWithInternalTeam = isset($data['relation_with_internal_team']) ? (int)$data['relation_with_internal_team'] : null;
        $this->probabilityOfConversion = isset($data['probability_of_conversion']) ? (int)$data['probability_of_conversion'] : null;
        $this->visitDescription = $data['visit_description'] ?? null;
        $this->followupNotes = $data['followup_notes'] ?? null;
        if (isset($data['visit_samples_provided'])) {
            if (is_string($data['visit_samples_provided'])) {
                $decoded = json_decode($data['visit_samples_provided'], true);
                $this->visitSamplesProvided = is_array($decoded) ? $decoded : null;
            } elseif (is_array($data['visit_samples_provided'])) {
                $this->visitSamplesProvided = $data['visit_samples_provided'];
            } else {
                $this->visitSamplesProvided = null;
            }
        } else {
            $this->visitSamplesProvided = null;
        }
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contactPerson,
            'gst_number' => $this->gstNumber,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_active' => $this->isActive,
            'region' => $this->region,
            'product_category' => $this->productCategory,
            'production_capacity' => $this->productionCapacity,
            'factory_locations' => $this->factoryLocations,
            'credit_limit' => $this->creditLimit,
            'payment_terms_days' => $this->paymentTermsDays,
            'technical_notes' => $this->technicalNotes,
            'products_introduced' => $this->productsIntroduced,
            'monthly_consumption' => $this->monthlyConsumption,
            'year_of_association' => $this->yearOfAssociation,
            'order_frequency' => $this->orderFrequency,
            'last_order_date' => $this->lastOrderDate,
            'last_visit_date' => $this->lastVisitDate,
            'payment_track' => $this->paymentTrack,
            'target_volume' => $this->targetVolume,
            'next_followup_date' => $this->nextFollowupDate,
            'assigned_sales_owner' => $this->assignedSalesOwner,
            'number_of_plants' => $this->numberOfPlants,
            'general_notes' => $this->generalNotes,
            'funnel_stage' => $this->funnelStage,
            'industry_type' => $this->industryType,
            'tiles_subtype' => $this->tilesSubtype,
            'monthly_consumption_ton' => $this->monthlyConsumptionTon,
            'avg_price_per_ton' => $this->avgPricePerTon,
            'current_supplier_details' => $this->currentSupplierDetails,
            'relation_with_purchase' => $this->relationWithPurchase,
            'relation_with_internal_team' => $this->relationWithInternalTeam,
            'probability_of_conversion' => $this->probabilityOfConversion,
            'visit_description' => $this->visitDescription,
            'followup_notes' => $this->followupNotes,
            'visit_samples_provided' => $this->visitSamplesProvided,
            'funnel_value' => ($this->monthlyConsumptionTon !== null && $this->avgPricePerTon !== null)
                ? round($this->monthlyConsumptionTon * $this->avgPricePerTon, 2) : null,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    public static function normalizeGstNumber(?string $gst): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string)$gst)));
    }

    public static function isValidGstFormat(string $gst): bool
    {
        if ($gst === '') {
            return false;
        }
        return (bool)preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gst);
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Party name is required';
        }

        if (empty($this->contactPerson)) {
            $errors[] = 'Contact person is required';
        }

        if (empty($this->gstNumber)) {
            $errors[] = 'GST number is required';
        } elseif (!self::isValidGstFormat($this->gstNumber)) {
            $errors[] = 'Invalid GST number format';
        }

        if (empty($this->phone)) {
            $errors[] = 'Phone is required';
        }

        if (empty($this->email)) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        return $errors;
    }
}



