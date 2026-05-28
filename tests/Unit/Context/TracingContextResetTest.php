<?php

use D076\Tracing\Context\TracingContext;

it('reset() clears every public property back to its default', function () {
    $ctx = new TracingContext();
    $props = (new ReflectionClass($ctx))->getProperties(ReflectionProperty::IS_PUBLIC);

    foreach ($props as $prop) {
        $typeName = $prop->getType() instanceof ReflectionNamedType
            ? $prop->getType()->getName()
            : 'mixed';

        $prop->setValue($ctx, match ($typeName) {
            'bool'      => false,
            'int'       => 999,
            'array'     => ['dirty'],
            'Throwable' => new Exception('dirty'),
            default     => 'dirty',
        });
    }

    $ctx->reset();

    foreach ($props as $prop) {
        $expected = $prop->getName() === 'shouldRecord' ? true : null;
        expect($prop->getValue($ctx))
            ->toBe($expected, "Property \${$prop->getName()} was not reset");
    }
});
