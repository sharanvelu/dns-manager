<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneProvider;
use App\Jobs\SyncEntryToProvider;
use Illuminate\Http\UploadedFile;
use App\Services\DnsEntryImporter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);
});

function csvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('entries.csv', $content);
}

function importCsv(string $csv)
{
    return test()->post('/entries/import', [
        'file' => csvUpload($csv),
        'dns_zone_id' => test()->zone->id,
    ]);
}

test('valid csv rows are imported into the zone and synced', function () {
    $provider = Provider::factory()->cloudflare()->create(['managed_record_types' => ['A', 'CNAME', 'MX']]);
    ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);

    $csv = <<<'CSV'
    name,type,content,ttl,priority,proxied,comment
    app,A,192.168.1.10,,,true,Web
    media,CNAME,app.example.com,300,,false,
    @,MX,mail.example.com,,10,false,Mail
    CSV;

    importCsv($csv)
        ->assertRedirect()
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 3 && $result['skipped'] === 0 && $result['failed'] === []);

    expect(DnsEntry::count())->toBe(3)
        ->and(DnsEntry::where('name', 'app')->sole()->proxied)->toBeTrue()
        ->and(DnsEntry::where('name', 'app')->sole()->dns_zone_id)->toBe($this->zone->id)
        ->and(DnsEntry::where('type', 'MX')->sole()->name)->toBe('@')
        ->and(DnsEntry::where('type', 'MX')->sole()->priority)->toBe(10);

    Queue::assertPushed(SyncEntryToProvider::class, 3);
});

test('pasted fqdns under the zone are stored relative', function () {
    importCsv("name,type,content\nwww.example.com,A,10.0.0.1\n")
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 1);

    expect(DnsEntry::sole()->name)->toBe('www');
});

test('invalid rows are reported with line numbers and valid rows still import', function () {
    $csv = <<<'CSV'
    name,type,content
    good,A,10.0.0.1
    bad name!,A,10.0.0.2
    nottype,BOGUS,10.0.0.3
    noip,A,not-an-ip
    CSV;

    importCsv($csv)->assertSessionHas('importResult', function ($result) {
        expect($result['imported'])->toBe(1)
            ->and($result['failed'])->toHaveCount(3)
            ->and(array_column($result['failed'], 'line'))->toBe([3, 4, 5]);

        return true;
    });

    expect(DnsEntry::count())->toBe(1);
});

test('duplicate entries within the zone are skipped', function () {
    DnsEntry::factory()->create([
        'dns_zone_id' => $this->zone->id, 'name' => 'app', 'type' => 'A', 'content' => '10.0.0.1',
    ]);

    importCsv("name,type,content\napp,A,10.0.0.1\nnew,A,10.0.0.2\n")
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 1 && $result['skipped'] === 1);

    expect(DnsEntry::count())->toBe(2);
});

test('the same record can be imported into a different zone', function () {
    $other = DnsZone::factory()->create(['name' => 'other.dev']);
    DnsEntry::factory()->create([
        'dns_zone_id' => $other->id, 'name' => 'app', 'type' => 'A', 'content' => '10.0.0.1',
    ]);

    importCsv("name,type,content\napp,A,10.0.0.1\n")
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 1 && $result['skipped'] === 0);

    expect(DnsEntry::where('name', 'app')->count())->toBe(2);
});

test('a missing or unknown zone rejects the import', function () {
    $this->post('/entries/import', ['file' => csvUpload("name,type,content\napp,A,10.0.0.1\n")])
        ->assertSessionHasErrors('dns_zone_id');

    $this->post('/entries/import', [
        'file' => csvUpload("name,type,content\napp,A,10.0.0.1\n"),
        'dns_zone_id' => 999999,
    ])->assertSessionHasErrors('dns_zone_id');

    expect(DnsEntry::count())->toBe(0);
});

test('missing required columns reject the whole file', function () {
    importCsv("name,ttl\nfoo,300\n")->assertSessionHasErrors('file');

    expect(DnsEntry::count())->toBe(0);
});

test('non-csv uploads are rejected', function () {
    $this->post('/entries/import', [
        'file' => UploadedFile::fake()->create('entries.pdf', 10, 'application/pdf'),
        'dns_zone_id' => $this->zone->id,
    ])->assertSessionHasErrors('file');
});

test('sample csv downloads, uses relative names, and is itself importable', function () {
    $response = $this->get('/entries/import/sample');

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=dns-entries-sample.csv');

    expect(DnsEntryImporter::sampleCsv())
        ->toContain("\nwww,A,")
        ->toContain("\n@,MX,")
        ->toContain("\n_dmarc,TXT,");

    importCsv(DnsEntryImporter::sampleCsv())
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 6 && $result['failed'] === []);
});

test('row limit is enforced', function () {
    $rows = "name,type,content\n";

    for ($i = 0; $i < 1001; $i++) {
        $rows .= "host{$i},A,10.0.0.1\n";
    }

    importCsv($rows)->assertSessionHasErrors('file');
});
