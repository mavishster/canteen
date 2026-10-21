<?php

use App\Support\I18n\BladeWrapper;

uses(Tests\TestCase::class);

function bwWrap(string $source): string
{
    return (new BladeWrapper)->wrap($source)[0];
}

it('wraps plain text, keeping the spaces around it', function () {
    expect(bwWrap('<h1>Products</h1>'))->toBe("<h1>{{ __('Products') }}</h1>")
        ->and(bwWrap('<button class="btn">  Save  </button>'))->toBe("<button class=\"btn\">  {{ __('Save') }}  </button>")
        ->and(bwWrap("<p>\n    Hello\n    world\n</p>"))->toBe("<p>\n    {{ __('Hello world') }}\n</p>");
});

it('wraps the useful attributes and the page title', function () {
    expect(bwWrap('<input placeholder="Search students" title="Find">'))
        ->toBe("<input placeholder=\"{{ __('Search students') }}\" title=\"{{ __('Find') }}\">")
        ->and(bwWrap("@section('title', 'Products')"))->toBe("@section('title', __('Products'))")
        ->and(bwWrap('<img alt="School logo">'))->toBe("<img alt=\"{{ __('School logo') }}\">");
});

it('leaves text mixed with a value for a person', function () {
    expect(bwWrap('<td>Held at {{ $when }} now</td>'))->toBe('<td>Held at {{ $when }} now</td>')
        ->and(bwWrap('<td>{{ $a->name }}</td>'))->toBe('<td>{{ $a->name }}</td>')
        ->and(bwWrap('<td>{!! $html !!} words</td>'))->toBe('<td>{!! $html !!} words</td>');
});

it('wraps each side of a directive as its own phrase', function () {
    expect(bwWrap('<td>@if ($a) Yes @else No @endif</td>'))
        ->toBe("<td>@if (\$a) {{ __('Yes') }} @else {{ __('No') }} @endif</td>");
});

it('does not break a directive that holds a comparison', function () {
    expect(bwWrap('<div>@if ($count > 1)<p>Many</p>@endif</div>'))
        ->toBe("<div>@if (\$count > 1)<p>{{ __('Many') }}</p>@endif</div>");
});

it('leaves scripts, styles, code, comments and php blocks alone', function () {
    $source = '<script>var a = "Hello there";</script><style>.a { color: red; }</style><code>php artisan</code>{{-- A comment --}}@php $x = 1; @endphp';

    expect(bwWrap($source))->toBe($source);
});

it('escapes an apostrophe', function () {
    expect(bwWrap("<p>Don't stop</p>"))->toBe("<p>{{ __('Don\\'t stop') }}</p>");
});

it('skips codes, numbers, symbols and entities', function () {
    foreach (['<td>USD</td>', '<td>12.50</td>', '<td>—</td>', '<td>A &amp; B</td>', '<td>&nbsp;</td>', '<a>/admin/products</a>', '<a>https://x.test</a>'] as $source) {
        expect(bwWrap($source))->toBe($source);
    }
});

it('does nothing the second time', function () {
    $once = bwWrap('<h1>Products</h1><p>Hello <b>there</b></p><input placeholder="Search">');

    expect(bwWrap($once))->toBe($once);
});

it('reports the phrases it wrapped, with the spaces tidied', function () {
    [, $keys] = (new BladeWrapper)->wrap("<h1>Products</h1><p>Hello   there</p><td>Held {{ \$x }} now</td>");

    expect($keys)->toBe(['Products', 'Hello there']);
});

it('keeps a view that holds expressions and directives valid', function () {
    $source = <<<'BLADE'
@extends('layouts.app')
@section('title', 'Products')
@section('content')
<h1 class="h3">Products</h1>
@foreach ($products as $product)
    <tr class="{{ $product->is_active ? '' : 'text-muted' }}">
        <td>{{ $product->name }}</td>
        <td><button class="btn btn-sm" onclick="return confirm('Sure?')">Hide</button></td>
    </tr>
@endforeach
@endsection
BLADE;

    $new = bwWrap($source);

    expect($new)->toContain("@section('title', __('Products'))")
        ->toContain("<h1 class=\"h3\">{{ __('Products') }}</h1>")
        ->toContain("{{ __('Hide') }}</button>")
        ->toContain('{{ $product->name }}')
        ->toContain("onclick=\"return confirm('Sure?')\"")
        ->toContain("@foreach (\$products as \$product)");

    // and it still compiles
    $compiled = \Illuminate\Support\Facades\Blade::compileString($new);
    expect($compiled)->toContain("__('Hide')");
});
