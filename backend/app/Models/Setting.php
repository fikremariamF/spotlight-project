<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable business settings. Values not stored fall back to config defaults.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];

    public const DEFAULTS = [
        'commission_rate_percent' => 'marketplace.commission.default_rate_percent',
        'cod_enabled' => 'marketplace.payments.cod_enabled',
        'online_payments_enabled' => 'marketplace.payments.online_enabled',
        'commission_credit_limit_birr' => 'marketplace.commission.credit_limit_birr',
        'settlement_instructions' => 'marketplace.settlements.instructions',
        'sms_enabled' => 'marketplace.alerts.sms_enabled',
    ];

    public static function value(string $key): mixed
    {
        $stored = static::query()->whereKey($key)->value('value');

        return $stored ?? config(self::DEFAULTS[$key] ?? '');
    }

    /**
     * Commission in basis points (10% = 1000), never a float in money maths.
     */
    public static function commissionRateBp(): int
    {
        return (int) round(((float) static::value('commission_rate_percent')) * 100);
    }

    /**
     * Unpaid commission above which a store cannot confirm new orders, in santim. 0 means no limit.
     */
    public static function creditLimitSantim(): int
    {
        return max(0, (int) static::value('commission_credit_limit_birr')) * 100;
    }

    public static function settlementInstructions(): string
    {
        return (string) static::value('settlement_instructions');
    }

    public static function codEnabled(): bool
    {
        return filter_var(static::value('cod_enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch for online payment. It also needs a working gateway; see PaymentService::available(). */
    public static function onlinePaymentsEnabled(): bool
    {
        return filter_var(static::value('online_payments_enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Admin switch that pauses every SMS alert (in-app and email alerts continue). */
    public static function smsEnabled(): bool
    {
        return filter_var(static::value('sms_enabled'), FILTER_VALIDATE_BOOL);
    }
}
