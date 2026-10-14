import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    labelPerangkat,
    labelPlatform,
    perangkatAktif,
} from '@/lib/perangkat';

describe('labelPlatform', () => {
    it('names the three DDL platforms', () => {
        assert.equal(labelPlatform('android'), 'Android');
        assert.equal(labelPlatform('ios'), 'iOS');
        assert.equal(labelPlatform('web'), 'Web');
    });
});

describe('labelPerangkat', () => {
    it('appends the app version when the server sent one', () => {
        assert.equal(
            labelPerangkat({ platform: 'android', app_versi: '2.1.0' }),
            'Perangkat Android 2.1.0',
        );
    });

    it('omits a missing or empty version', () => {
        assert.equal(
            labelPerangkat({ platform: 'ios', app_versi: null }),
            'Perangkat iOS',
        );
        assert.equal(
            labelPerangkat({ platform: 'web', app_versi: '' }),
            'Perangkat Web',
        );
    });
});

describe('perangkatAktif', () => {
    it('keeps active rows and treats a missing flag as active', () => {
        assert.equal(perangkatAktif({ aktif: true } as never), true);
        assert.equal(perangkatAktif({} as never), true);
    });

    it('drops a revoked row', () => {
        assert.equal(perangkatAktif({ aktif: false } as never), false);
    });
});
