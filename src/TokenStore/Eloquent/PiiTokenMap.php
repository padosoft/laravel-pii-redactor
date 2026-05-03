<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model backing the `pii_token_maps` table.
 *
 * `created_at` is filled by the DB default (`useCurrent()`), so we
 * disable Eloquent's automatic timestamps to avoid clashing with the
 * column shape (no `updated_at`).
 *
 * `detector` is denormalised out of the token literal so operators can
 * scope dumps / rotations to a single detector type without parsing
 * `[tok:<detector>:<hex>]` strings every time.
 *
 * @property int $id
 * @property string $token
 * @property string $original
 * @property string $detector
 * @property \Illuminate\Support\Carbon $created_at
 */
class PiiTokenMap extends Model
{
    protected $table = 'pii_token_maps';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['token', 'original', 'detector'];

    public $incrementing = true;
}
