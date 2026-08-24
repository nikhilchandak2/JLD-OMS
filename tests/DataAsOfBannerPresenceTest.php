<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Grep evidence: every screen that renders an outstanding or credit figure
 * must include the shared DataAsOfBanner.
 */
class DataAsOfBannerPresenceTest extends TestCase
{
    public function testOutstandingScreensIncludeTheBanner(): void
    {
        $root = dirname(__DIR__);
        $screens = [
            'templates/new-order.php',
            'templates/dispatch-dashboard.php',
            'templates/admin/credit-approvals.php',
            'templates/crm/party-detail.php',
            'templates/crm/import-receivables.php',
        ];

        foreach ($screens as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            $this->assertNotFalse($contents, $relative . ' should exist');
            $this->assertStringContainsString(
                'data-as-of-banner',
                $contents,
                $relative . ' renders outstanding/credit figures without DataAsOfBanner'
            );
        }
    }

    public function testWebhookIsMarkedInert(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controllers/BusyIntegrationController.php');
        $this->assertStringContainsString('http_response_code(410)', $controller);
        $this->assertStringContainsString('inert', strtolower($controller));
        $this->assertStringNotContainsString(
            'processInvoice($invoiceData)',
            $controller,
            'Webhook must not call processInvoice'
        );
    }
}
