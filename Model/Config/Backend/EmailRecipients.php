<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\AiVisibility\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Panth\AiVisibility\Model\Config;

/**
 * Validates and normalises the weekly summary recipients before they are saved.
 *
 * Every address must be valid (no silent dropping on save), at most Config::EMAIL_MAX_RECIPIENTS,
 * and at least one when the email is enabled. The stored value is "a@shop.test, b@shop.test".
 */
class EmailRecipients extends Value
{
    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $this->setValue(self::normalize((string)$this->getValue(), $this->isEmailEnabled()));
        return parent::beforeSave();
    }

    /**
     * Validate a recipient list and return it comma separated
     *
     * @param string $raw
     * @param bool $required At least one address needed (email enabled)
     * @return string
     * @throws LocalizedException
     */
    public static function normalize(string $raw, bool $required): string
    {
        $list = Config::splitEmailList($raw);
        if ($list['invalid'] !== []) {
            throw new LocalizedException(
                __('Weekly summary recipients: invalid email address(es) %1.', implode(', ', $list['invalid']))
            );
        }
        if (count($list['valid']) > Config::EMAIL_MAX_RECIPIENTS) {
            throw new LocalizedException(
                __('Weekly summary recipients: enter at most %1 addresses.', Config::EMAIL_MAX_RECIPIENTS)
            );
        }
        if ($required && $list['valid'] === []) {
            throw new LocalizedException(
                __('Weekly summary recipients: enter at least one address or switch the weekly summary off.')
            );
        }
        return implode(', ', $list['valid']);
    }

    /**
     * Whether "Send Weekly Summary" is switched on in the same save (falls back to the stored value)
     *
     * @return bool
     */
    private function isEmailEnabled(): bool
    {
        $posted = $this->getFieldsetDataValue('enabled');
        if ($posted !== null) {
            return (bool)(int)$posted;
        }
        return $this->_config->isSetFlag(Config::XML_PATH_EMAIL_ENABLED);
    }
}
