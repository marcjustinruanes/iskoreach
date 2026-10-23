<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_private_images_and_pdf_without_forcing_download(): void
    {
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $url = route('provider-verification.show', [$profile, 'representative_id']);
        $this->actingAs($admin)->get($url.'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('inline;', $this->get($url.'?preview=1')->headers->get('Content-Disposition'));
        $this->get($url)->assertDownload('representative_id.jpg');
        $this->get('/admin/providers/'.$profile->id)->assertInertia(fn (Assert $page) => $page->where('provider.documents.0.mime', 'image/jpeg')->where('provider.documents.0.preview_url', $url.'?preview=1'));
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        Storage::disk('documents')->put('verification-preview.pdf', $pdf);
        $documents = $profile->verification_documents;
        $documents['organization_proof'] = ['path' => 'verification-preview.pdf', 'name' => 'organization.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf)];
        $profile->forceFill(['verification_documents' => $documents])->save();
        $this->get(route('provider-verification.show', [$profile, 'organization_proof', 'preview' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_preview_keeps_authorization_and_rejects_modified_or_missing_documents(): void
    {
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $url = route('provider-verification.show', [$profile, 'representative_id', 'preview' => 1]);
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(ProviderProfile::factory()->create()->user)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url)->assertOk();
        Storage::disk('documents')->put($profile->verification_documents['representative_id']['path'], 'tampered');
        $this->get($url)->assertStatus(409);
        Storage::disk('documents')->delete($profile->verification_documents['representative_id']['path']);
        $this->get($url)->assertNotFound();
        $path = $profile->verification_documents['representative_id']['path'];
        $content = '<html><body>Unsupported document</body></html>';
        Storage::disk('documents')->put($path, $content);
        $documents = $profile->verification_documents;
        $documents['representative_id']['sha256'] = hash('sha256', $content);
        $profile->forceFill(['verification_documents' => $documents])->save();
        $this->get($url)->assertStatus(415);
    }
}
