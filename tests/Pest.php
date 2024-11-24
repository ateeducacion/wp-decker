<?php

function isUnitTest() {
	return ! empty( $GLOBALS['argv'] ) && '--group=unit' === $GLOBALS['argv'][1];
}

use Yoast\WPTestUtils\WPIntegration\TestCase;

uses( TestCase::class );
