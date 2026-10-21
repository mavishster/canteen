<?php

use App\Support\I18n\PhpWrapper;

function pwWrap(string $body): string
{
    return (new PhpWrapper)->wrap("<?php\n" . $body)[0];
}

function pwSame(string $in, string $out): void
{
    expect(pwWrap($in))->toBe("<?php\n" . $out);
}

it('wraps a flash message', function () {
    pwSame(
        "return back()->with('status', 'Saved.');",
        "return back()->with('status', __('Saved.'));"
    );
});

it('wraps the text of an error, not its field name', function () {
    pwSame(
        "return back()->withErrors(['staff' => 'You cannot disable your own account.']);",
        "return back()->withErrors(['staff' => __('You cannot disable your own account.')]);"
    );
});

it('wraps validation exception messages', function () {
    pwSame(
        "throw ValidationException::withMessages(['price' => 'The price is too small.']);",
        "throw ValidationException::withMessages(['price' => __('The price is too small.')]);"
    );
});

it('wraps the message of abort, abort_if and abort_unless', function () {
    pwSame("abort(403, 'Log in as a school admin.');", "abort(403, __('Log in as a school admin.'));");
    pwSame("abort_unless(\$ok, 403, 'Log in as a school admin.');", "abort_unless(\$ok, 403, __('Log in as a school admin.'));");
    pwSame("abort_if(\$bad, 404, 'Nothing here for you.');", "abort_if(\$bad, 404, __('Nothing here for you.'));");
});

it('wraps the message of a JSON answer and leaves the code alone', function () {
    pwSame(
        "return response()->json(['message' => 'Not allowed here.', 'reason' => 'not_allowed'], 403);",
        "return response()->json(['message' => __('Not allowed here.'), 'reason' => 'not_allowed'], 403);"
    );
});

it('wraps the message of an exception class but not its reason code', function () {
    $in = <<<'PHP'
class CardException extends \RuntimeException
{
    public static function inactive(): self
    {
        return new self('student_inactive', 'This student account is inactive.');
    }
}
PHP;

    expect(pwWrap($in))->toContain("new self('student_inactive', __('This student account is inactive.'))");
});

it('does not touch new self() in a class that is not an exception', function () {
    $in = "class Money\n{\n    public static function a(): self\n    {\n        return new self('one', 'Some text here.');\n    }\n}";

    pwSame($in, $in);
});

it('wraps InvalidArgumentException, also written with a backslash', function () {
    pwSame("throw new InvalidArgumentException('That product belongs to another school.');", "throw new InvalidArgumentException(__('That product belongs to another school.'));");
    pwSame("throw new \\InvalidArgumentException('Another problem here.');", "throw new \\InvalidArgumentException(__('Another problem here.'));");
});

it('turns a concatenation into a template with a placeholder', function () {
    pwSame(
        "abort(403, 'No more than ' . Money::format(\$max, \$c) . '.');",
        "abort(403, __('No more than :value.', ['value' => Money::format(\$max, \$c)]));"
    );
});

it('turns a double quoted string with values into a template', function () {
    pwSame(
        "return back()->with('status', \"{\$user->name} is now {\$data['role']}.\");",
        "return back()->with('status', __(':name is now :role.', ['name' => \$user->name, 'role' => \$data['role']]));"
    );

    pwSame(
        "return back()->with('status', \"Hello \$name, welcome\");",
        "return back()->with('status', __('Hello :name, welcome', ['name' => \$name]));"
    );
});

it('gives each placeholder its own name', function () {
    pwSame(
        "return back()->with('status', \"{\$a->name} and {\$b->name} met\");",
        "return back()->with('status', __(':name and :name2 met', ['name' => \$a->name, 'name2' => \$b->name]));"
    );
});

it('escapes an apostrophe', function () {
    pwSame(
        "return back()->with('status', \"Don't stop.\");",
        "return back()->with('status', __('Don\\'t stop.'));"
    );
});

it('leaves alone what is not a message', function () {
    foreach ([
        "\$title = 'A plain string';",
        "return back()->withErrors(['stock' => \$e->getMessage()]);",
        "return back()->with('status', __('Saved.'));",
        "abort(404, 'not_found');",
        "return redirect()->route('admin.products');",
    ] as $code) {
        pwSame($code, $code);
    }
});

it('lists what needs a person: a condition, a sprintf', function () {
    [$new, , $skipped] = (new PhpWrapper)->wrap("<?php\nreturn back()->with('status', \$on ? 'Switched on.' : 'Switched off.');\nabort(403, sprintf('Hello %s there', \$name));");

    expect($new)->toContain("\$on ? 'Switched on.' : 'Switched off.'")
        ->and($skipped)->toHaveCount(2)
        ->and($skipped[0]['line'])->toBe(2)
        ->and($skipped[0]['text'])->toContain('needs a person');
});

it('does nothing the second time', function () {
    $once = pwWrap("return back()->with('status', \"{\$user->name} is now {\$role}.\")->withErrors(['x' => 'Problem here.']);");

    expect(pwWrap(substr($once, 6)))->toBe($once);
});

it('reports the phrases it wrapped', function () {
    [, $keys] = (new PhpWrapper)->wrap("<?php\nabort(403, 'First problem.');\nreturn back()->with('status', \"{\$a->name} done.\");");

    expect($keys)->toBe(['First problem.', ':name done.']);
});
