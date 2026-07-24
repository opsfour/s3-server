<?php

declare(strict_types=1);

/**
 * @template T of object
 * @param class-string<T>|null $abstract
 * @return ($abstract is null ? \Illuminate\Contracts\Foundation\Application : T)
 */
function app(?string $abstract = null): object {}

function config(string $key, mixed $default = null): mixed {}

function config_path(string $path = ''): string {}

function storage_path(string $path = ''): string {}
