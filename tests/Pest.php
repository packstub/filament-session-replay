<?php

use Packstub\SessionReplay\Tests\AssistTestCase;
use Packstub\SessionReplay\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(AssistTestCase::class)->in('Assist');
