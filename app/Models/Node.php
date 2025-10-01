<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XBoard Node Model
 * 
 * @property int $id
 * @property string $name
 * @property string $domain
 * @property string $api_url
 * @property string|null $country_code
 * @property string|null $owner
 * @property bool $is_default
 * @property bool $is_required
 * @property bool $is_active
 * @property int $priority
 * @property string|null $description
 * @property array|null $endpoints
 * @property array|null $endpoint_permissions
 * @property array|null $features
 * @property \Carbon\Carbon|null $last_health_check
 * @property string $health_status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * Представляет узел в системе XBoard - это может быть:
 * - XBoard API (доска объявлений P2P обменов)
 * - Core API (ядро для работы с блокчейном)
 * 
 * Узлы работают как независимые серверы, подобные почтовым серверам,
 * а клиент XBoard - это почтовый клиент, умеющий работать с несколькими узлами
 */
class Node extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'api_url',
        'country_code',
        'owner',
        'is_default',
        'is_required',
        'is_active',
        'priority',
        'description',
        'endpoints',
        'endpoint_permissions',
        'features',
        'last_health_check',
        'health_status'
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'endpoints' => 'array',
        'endpoint_permissions' => 'array',
        'features' => 'array',
        'last_health_check' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Получить доступные endpoints для клиента в зависимости от его роли
     */
    public function getAccessibleEndpoints(string $userRole = 'public'): array
    {
        if (!$this->endpoints || !$this->endpoint_permissions) {
            return [];
        }
        
        $accessible = [];
        
        foreach ($this->endpoints as $key => $url) {
            $permission = $this->endpoint_permissions[$key] ?? 'public';
            
            // Проверяем доступ
            if ($permission === 'public' || $userRole === 'admin') {
                $accessible[$key] = $url;
            } elseif ($permission === 'developer' && in_array($userRole, ['developer', 'admin'])) {
                $accessible[$key] = $url;
            }
        }
        
        return $accessible;
    }

    /**
     * Получить активные узлы
     */
    public static function getActive()
    {
        return self::where('is_active', true)
            ->orderBy('priority', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить обязательные узлы (всегда подключенные)
     */
    public static function getRequired()
    {
        return self::where('is_required', true)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Получить узел по умолчанию
     */
    public static function getDefault()
    {
        return self::where('is_default', true)
            ->where('is_active', true)
            ->first();
    }


    /**
     * Проверить доступность узла
     */
    public function checkHealth(): bool
    {
        try {
            $response = @file_get_contents($this->api_url . '/api/v1/health');
            $this->last_health_check = now();
            
            if ($response !== false) {
                $this->health_status = 'online';
                $this->save();
                return true;
            }
            
            $this->health_status = 'offline';
            $this->save();
            return false;
        } catch (\Exception $e) {
            $this->health_status = 'offline';
            $this->save();
            return false;
        }
    }
}

