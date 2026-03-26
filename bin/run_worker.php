<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
}

require_once $autoload;

use DanilKashin\Worker\Worker;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function castParam(string $type, mixed $value): mixed
{
    return match ($type) {
        'int'   => (int) $value,
        'float' => (float) $value,
        'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        default => $value,
    };
}

if ($argc < 2) {
    fail(sprintf('Usage: php %s <FullyQualifiedWorkerClass> [--param=value ...]', $argv[0]));
}

$workerClass = $argv[1];

if (!class_exists($workerClass)) {
    fail(sprintf('Worker class "%s" not found.', $workerClass));
}

$reflection = new ReflectionClass($workerClass);

if (!$reflection->isInstantiable()) {
    fail(sprintf('Worker "%s" is not instantiable.', $workerClass));
}

if (!$reflection->isSubclassOf(Worker::class)) {
    fail(sprintf('Class "%s" is not a subclass of Worker.', $workerClass));
}

$params = [];
for ($i = 2; $i < $argc; $i++) {
    if (1 === preg_match('/^--([^=]+)=(.*)$/', $argv[$i], $matches)) {
        $params[$matches[1]] = $matches[2];
    } elseif (preg_match('/^--([^=]+)$/', $argv[$i], $matches)) {
        $params[$matches[1]] = true;
    }
}

$namedArgs = [];
foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
    $name = $param->getName();

    if (array_key_exists($name, $params)) {
        $type = $param->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
        $namedArgs[$name] = castParam($typeName, $params[$name]);
    } elseif (!$param->isDefaultValueAvailable()) {
        fail(sprintf('Missing required parameter "--%s" for worker "%s".', $name, $workerClass));
    }
}

/** @var Worker $worker */
$worker = new $workerClass(...$namedArgs);
$worker->run();