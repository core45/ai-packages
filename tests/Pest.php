<?php

declare(strict_types=1);

/*
 * Pest requires a root tests/ directory to exist before it will boot, but the
 * tests themselves live under packages/<package>/tests so each package carries
 * its own Testbench harness and stays self-contained when split out to its own
 * repository.
 *
 * The suites are declared in phpunit.xml, one per package, and Pest runs them
 * from there. This file exists to satisfy the bootstrapper and to give
 * Pest-style tests somewhere to register global helpers later; it deliberately
 * binds no base class, because each package's tests already extend their own.
 */
