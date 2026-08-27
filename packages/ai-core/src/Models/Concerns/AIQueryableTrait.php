<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

/**
 * Provides a default AIQueryable implementation for models.
 *
 * Models using this trait may define:
 * - protected array $aiQueryableFields (optional, defaults to fillable + id, timestamps)
 * - protected array $aiFilterScopes (optional, defaults to detected common scopes)
 * - protected array $aiActions (optional, defaults to activate/deactivate)
 * - protected ?string $aiFilamentResource (optional)
 * - protected string $aiModelLabel (optional, defaults to class name)
 * - protected string $aiModelPluralLabel (optional, defaults to label + 's')
 */
trait AIQueryableTrait
{
    /**
     * @return array<int, string>
     */
    public function getAIQueryableFields(): array
    {
        if (property_exists($this, 'aiQueryableFields') && !empty($this->aiQueryableFields)) {
            return $this->aiQueryableFields;
        }

        $fields = ['id'];

        $sensitiveFields = ['password', 'remember_token', 'api_token', 'secret'];
        $fillable = array_diff($this->getFillable(), $sensitiveFields);
        $fields = array_merge($fields, $fillable);

        if (property_exists($this, 'translatedAttributes')) {
            $fields = array_merge($fields, $this->translatedAttributes);
        }

        if ($this->timestamps) {
            $fields[] = 'created_at';
            $fields[] = 'updated_at';
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return array<string, string>
     */
    public function getAIFilterScopes(): array
    {
        if (property_exists($this, 'aiFilterScopes')) {
            return $this->aiFilterScopes;
        }

        $scopes = [];

        if (method_exists($this, 'scopeActive')) {
            $scopes['active'] = 'Only active items';
        }
        if (method_exists($this, 'scopeInactive')) {
            $scopes['inactive'] = 'Only inactive items';
        }
        if (method_exists($this, 'scopePublished')) {
            $scopes['published'] = 'Only published items';
        }
        if (method_exists($this, 'scopeRecent')) {
            $scopes['recent'] = 'Recently created items';
        }

        return $scopes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAIActions(): array
    {
        if (property_exists($this, 'aiActions')) {
            return $this->aiActions;
        }

        $actions = [];

        if (in_array('active', $this->getFillable(), true)) {
            $actions['activate'] = [
                'description' => 'Activate this item',
                'fields' => [],
            ];
            $actions['deactivate'] = [
                'description' => 'Deactivate this item',
                'fields' => [],
            ];
        }

        return $actions;
    }

    public function getFilamentResource(): ?string
    {
        if (property_exists($this, 'aiFilamentResource')) {
            return $this->aiFilamentResource;
        }

        return null;
    }

    public static function getAIModelLabel(): string
    {
        $instance = new static;
        if (property_exists($instance, 'aiModelLabel')) {
            return $instance->aiModelLabel;
        }

        return class_basename(static::class);
    }

    public static function getAIModelPluralLabel(): string
    {
        $instance = new static;
        if (property_exists($instance, 'aiModelPluralLabel')) {
            return $instance->aiModelPluralLabel;
        }

        $label = static::getAIModelLabel();

        return str_ends_with($label, 's') ? $label : $label.'s';
    }
}
