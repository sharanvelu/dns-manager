<?php

use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use App\Services\DnsEntryImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

function csvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('entries.csv', $content);
}

test('valid csv rows are imported and synced', function () {
    Provider::factory()->cloudflare()->create(['managed_record_types' => ['A', 'CNAME', 'MX']]);

    $csv = <<<'CSV'
    name,type,content,ttl,priority,proxied,comment
    app.example.com,A,192.168.1.10,,,true,Web
    media.example.com,CNAME,app.example.com,300,,false,
    example.com,MX,mail.example.com,,10,false,Mail
    CSV;

    $response = $this->post('/entries/import', ['file' => csvUpload($csv)]);

    $response->assertRedirect()->assertSessionHas('importResult', fn ($result) => $result['imported'] === 3 && $result['skipped'] === 0 && $result['failed'] === []);

    expect(DnsEntry::count())->toBe(3)
        ->and(DnsEntry::where('name', 'app.example.com')->sole()->proxied)->toBeTrue()
        ->and(DnsEntry::where('type', 'MX')->sole()->priority)->toBe(10);

    Queue::assertPushed(SyncEntryToProvider::class, 3);
});

test('invalid rows are reported with line numbers and valid rows still import', function () {
    $csv = <<<'CSV'
    name,type,content
    good.example.com,A,10.0.0.1
    bad name!,A,10.0.0.2
    nottype.example.com,BOGUS,10.0.0.3
    noip.example.com,A,not-an-ip
    CSV;

    $this->post('/entries/import', ['file' => csvUpload($csv)])
        ->assertSessionHas('importResult', function ($result) {
            expect($result['imported'])->toBe(1)
                ->and($result['failed'])->toHaveCount(3)
                ->and(array_column($result['failed'], 'line'))->toBe([3, 4, 5]);

            return true;
        });

    expect(DnsEntry::count())->toBe(1);
});

test('duplicate entries are skipped', function () {
    DnsEntry::factory()->create(['name' => 'app.example.com', 'type' => 'A', 'content' => '10.0.0.1']);

    $csv = "name,type,content\napp.example.com,A,10.0.0.1\nnew.example.com,A,10.0.0.2\n";

    $this->post('/entries/import', ['file' => csvUpload($csv)])
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 1 && $result['skipped'] === 1);

    expect(DnsEntry::count())->toBe(2);
});

test('missing required columns reject the whole file', function () {
    $this->post('/entries/import', ['file' => csvUpload("name,ttl\nfoo.example.com,300\n")])
        ->assertSessionHasErrors('file');

    expect(DnsEntry::count())->toBe(0);
});

test('non-csv uploads are rejected', function () {
    $this->post('/entries/import', ['file' => UploadedFile::fake()->create('entries.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
});

test('sample csv downloads and is itself importable', function () {
    $response = $this->get('/entries/import/sample');

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=dns-entries-sample.csv');

    $this->post('/entries/import', ['file' => csvUpload(DnsEntryImporter::sampleCsv())])
        ->assertSessionHas('importResult', fn ($result) => $result['imported'] === 6 && $result['failed'] === []);
});

test('row limit is enforced', function () {
    $rows = "name,type,content\n";
    for ($i = 0; $i < 1001; $i++) {
        $rows .= "host{$i}.example.com,A,10.0.0.1\n";
    }

    $this->post('/entries/import', ['file' => csvUpload($rows)])
        ->assertSessionHasErrors('file');
});
