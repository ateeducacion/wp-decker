const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('collaboration destroy cleanup unregisters listeners and disables callbacks', () => {
    const collaborationFile = path.resolve(
        __dirname,
        '../../public/assets/js/decker-collaboration.js'
    );
    const source = fs.readFileSync(collaborationFile, 'utf8');

    assert.match(source, /let remoteCursorChangeHandler = null;/);
    assert.match(source, /const awarenessStatusChangeHandler = \(\) => \{/);
    assert.match(source, /const providerStatusChangeHandler = \(\) => \{/);
    assert.match(source, /isDisabled = true;/);
    assert.match(
        source,
        /awareness\.off\('change', awarenessStatusChangeHandler\);/
    );
    assert.match(
        source,
        /awareness\.off\('change', remoteCursorChangeHandler\);/
    );
    assert.match(
        source,
        /provider\.off\('status', providerStatusChangeHandler\);/
    );
});
