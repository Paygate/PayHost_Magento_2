<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Model;

/**
 * Paygate payment information model
 *
 * Aware of all Paygate payment methods
 * Collects and provides access to Paygate-specific payment data
 * Provides business logic information about payment flow
 */
class Info
{
    /**
     * Apply a filter after getting value
     *
     * @param string $value
     *
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _getValue($value)
    {
        $label       = '';
        $outputValue = implode(', ', (array)$value);

        return sprintf('#%s%s', $outputValue, $outputValue == $label ? '' : ': ' . $label);
    }

}
