<?php
/**
 * The Pro SEO registrar: five operations into the free SEO module's identity.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Pro\Seo\ProSeo;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class ProSeoTest extends TestCase {

	public function test_it_registers_the_five_operations_reads_as_handlers_and_writes_as_write_operations(): void {
		$registry = new CapabilityRegistry();

		( new ProSeo( new Licence(), new SeoPresence() ) )->register( $registry );

		$ids = ProSeo::operation_ids();
		$this->assertCount( 5, $ids );

		foreach ( $ids as $id ) {
			$this->assertTrue( $registry->has( $id ), $id );
			$this->assertSame( ModuleId::Seo, $registry->definition( $id )->module, $id );
			$this->assertStringContainsString( 'SiteHelm Pro', $registry->definition( $id )->description, $id );
		}

		$this->assertTrue( $registry->hasWriteOperation( 'seo-settings-set' ) );
		$this->assertTrue( $registry->hasWriteOperation( 'content-seo-bulk-set' ) );
		$this->assertFalse( $registry->hasWriteOperation( 'seo-settings-get' ) );
		$this->assertIsCallable( $registry->handler( 'seo-404-log-list' ) );
	}

	public function test_registering_twice_is_refused_by_the_registry(): void {
		$registry  = new CapabilityRegistry();
		$registrar = new ProSeo( new Licence(), new SeoPresence() );
		$registrar->register( $registry );

		$this->expectException( \Throwable::class );
		$registrar->register( $registry );
	}
}
