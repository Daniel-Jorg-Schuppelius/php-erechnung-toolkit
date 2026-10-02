<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsConnectRequestTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper\IdsConnect;

use ERechnungToolkit\Enums\IdsConnectAction;
use ERechnungToolkit\Helper\IdsConnect\IdsConnectRequest;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

class IdsConnectRequestTest extends BaseTestCase {
    public function test_receive_cart_fields(): void {
        $fields = IdsConnectRequest::formFields(IdsConnectAction::ReceiveCart, 'https://app.example/ids/return/abc', 'K-4711', 'meister', 'geheim');

        $this->assertSame(['kndnr' => 'K-4711', 'name_kunde' => 'meister', 'pw_kunde' => 'geheim', 'action' => 'WKE', 'hookurl' => 'https://app.example/ids/return/abc'], $fields);
    }

    public function test_deep_link_needs_no_hook_but_article(): void {
        $this->assertSame(['action' => 'ADL', 'ghnummer' => 'WT-200'], IdsConnectRequest::formFields(IdsConnectAction::ArticleDeepLink, articleNumber: 'WT-200'));

        $this->expectException(InvalidArgumentException::class);
        IdsConnectRequest::formFields(IdsConnectAction::ArticleDeepLink);
    }

    public function test_rejects_missing_or_too_long_hook_url(): void {
        $this->expectException(InvalidArgumentException::class);
        IdsConnectRequest::formFields(IdsConnectAction::ReceiveCart, 'https://app.example/' . str_repeat('x', 300));
    }

    public function test_send_cart_needs_cart(): void {
        $this->expectException(InvalidArgumentException::class);
        IdsConnectRequest::formFields(IdsConnectAction::SendCart, 'https://app.example/ids/return/abc');
    }
}
