<?php

use D076\Tracing\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(D076\Tracing\Tests\CustomConnectionTestCase::class)->in('Integration');
uses(D076\Tracing\Tests\DisabledTestCase::class)->in('MasterSwitch');
