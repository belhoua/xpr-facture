<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les référentiels (devises, rôles) sont des prérequis du schéma :
     * companies.default_currency est une FK. Seedés une fois après
     * migrate:fresh, hors transaction de test.
     */
    protected bool $seed = true;
}
