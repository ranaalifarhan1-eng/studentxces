<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\TenantStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantStorageIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected User $adminA;
    protected User $adminB;
    protected User $superAdmin;
    protected Student $studentA;
    protected Student $studentB;
    protected Staff $staffA;
    protected Staff $staffB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        // School A
        $this->schoolA = School::create([
            'name'   => 'School Alpha Storage Test',
            'slug'   => 'school-alpha-storage-' . uniqid(),
            'code'   => 'SAS' . rand(1000, 9999),
            'status' => 'active',
        ]);

        $this->adminA = User::create([
            'school_id' => $this->schoolA->id,
            'name'      => 'Admin Alpha Storage',
            'email'     => 'admin.alpha.storage.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->adminA->assignRole('school-admin');

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class 1A', 'numeric_name' => 1]);
        $this->studentA = Student::create([
            'school_id'  => $this->schoolA->id,
            'class_id'   => $classA->id,
            'first_name' => 'Student',
            'last_name'  => 'Alpha',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $this->staffA = Staff::create([
            'school_id'   => $this->schoolA->id,
            'first_name'  => 'Teacher',
            'last_name'   => 'Alpha',
            'gender'      => 'female',
            'salary_type' => 'fixed',
            'status'      => 'active',
        ]);

        // School B
        $this->schoolB = School::create([
            'name'   => 'School Beta Storage Test',
            'slug'   => 'school-beta-storage-' . uniqid(),
            'code'   => 'SBS' . rand(1000, 9999),
            'status' => 'active',
        ]);

        $this->adminB = User::create([
            'school_id' => $this->schoolB->id,
            'name'      => 'Admin Beta Storage',
            'email'     => 'admin.beta.storage.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->adminB->assignRole('school-admin');

        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);
        $this->studentB = Student::create([
            'school_id'  => $this->schoolB->id,
            'class_id'   => $classB->id,
            'first_name' => 'Student',
            'last_name'  => 'Beta',
            'gender'     => 'female',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $this->staffB = Staff::create([
            'school_id'   => $this->schoolB->id,
            'first_name'  => 'Teacher',
            'last_name'   => 'Beta',
            'gender'      => 'male',
            'salary_type' => 'fixed',
            'status'      => 'active',
        ]);

        // Super Admin
        $this->superAdmin = User::create([
            'school_id' => null,
            'name'      => 'Super Admin Storage',
            'email'     => 'superadmin.storage.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');
    }

    /**
     * Test 1: School A student document is uploaded and stored under School A tenant path.
     */
    public function test_student_document_upload_is_isolated_to_school_path(): void
    {
        $file = UploadedFile::fake()->create('birth_cert.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->adminA)->post("/school/students/{$this->studentA->id}/documents", [
            'title' => 'Birth Certificate',
            'file'  => $file,
        ]);

        $response->assertStatus(302);

        $doc = StudentDocument::where('student_id', $this->studentA->id)->latest()->first();
        $this->assertNotNull($doc);
        $this->assertEquals($this->schoolA->id, $doc->school_id);

        // Path must match tenant directory convention: schools/{school_id}/students/{student_id}/documents/...
        $expectedDir = "schools/{$this->schoolA->id}/students/{$this->studentA->id}/documents";
        $this->assertStringStartsWith($expectedDir, $doc->file_path);

        // File must physically exist in private disk
        $this->assertTrue(Storage::disk('private')->exists($doc->file_path));
    }

    /**
     * Test 2: School B student document is stored under School B tenant path.
     */
    public function test_school_b_student_document_is_isolated_to_school_b_path(): void
    {
        $file = UploadedFile::fake()->create('beta_cert.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->adminB)->post("/school/students/{$this->studentB->id}/documents", [
            'title' => 'Beta Certificate',
            'file'  => $file,
        ]);

        $response->assertStatus(302);

        $doc = StudentDocument::where('student_id', $this->studentB->id)->latest()->first();
        $this->assertNotNull($doc);
        $this->assertEquals($this->schoolB->id, $doc->school_id);

        $expectedDir = "schools/{$this->schoolB->id}/students/{$this->studentB->id}/documents";
        $this->assertStringStartsWith($expectedDir, $doc->file_path);
        $this->assertTrue(Storage::disk('private')->exists($doc->file_path));
    }

    /**
     * Test 3: School A cannot upload document for School B student.
     */
    public function test_school_a_cannot_upload_document_for_school_b_student(): void
    {
        $file = UploadedFile::fake()->create('hacked_cert.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->adminA)->post("/school/students/{$this->studentB->id}/documents", [
            'title' => 'Cross Tenant Upload',
            'file'  => $file,
        ]);

        // Route model binding (SchoolScope) or controller check blocks with 404 or 403
        $this->assertTrue(in_array($response->status(), [403, 404]));
        $this->assertDatabaseMissing('student_documents', ['title' => 'Cross Tenant Upload']);
    }

    /**
     * Test 4: School A cannot download School B private student document.
     */
    public function test_school_a_cannot_download_school_b_student_document(): void
    {
        $path = TenantStorage::studentDocumentPath($this->schoolB->id, $this->studentB->id, 'confidential_beta.pdf');
        Storage::disk('private')->put($path, 'Confidential Student Data Beta');

        $docB = StudentDocument::create([
            'school_id'  => $this->schoolB->id,
            'student_id' => $this->studentB->id,
            'title'      => 'Confidential Beta Report',
            'file_path'  => $path,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $response = $this->actingAs($this->adminA)->get("/school/students/documents/{$docB->id}/download");

        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /**
     * Test 5: School A cannot delete School B student document.
     */
    public function test_school_a_cannot_delete_school_b_student_document(): void
    {
        $path = TenantStorage::studentDocumentPath($this->schoolB->id, $this->studentB->id, 'protected_doc.pdf');
        Storage::disk('private')->put($path, 'Protected Content');

        $docB = StudentDocument::create([
            'school_id'  => $this->schoolB->id,
            'student_id' => $this->studentB->id,
            'title'      => 'Protected Document',
            'file_path'  => $path,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $response = $this->actingAs($this->adminA)->delete("/school/students/documents/{$docB->id}");

        $this->assertTrue(in_array($response->status(), [403, 404]));
        $this->assertDatabaseHas('student_documents', ['id' => $docB->id]);
        $this->assertTrue(Storage::disk('private')->exists($path));
    }

    /**
     * Test 6: School A cannot download/delete School B staff document.
     */
    public function test_school_a_cannot_download_or_delete_school_b_staff_document(): void
    {
        $path = TenantStorage::staffDocumentPath($this->schoolB->id, $this->staffB->id, 'staff_contract_beta.pdf');
        Storage::disk('private')->put($path, 'Staff Contract Data');

        $docB = StaffDocument::create([
            'school_id'  => $this->schoolB->id,
            'staff_id'   => $this->staffB->id,
            'title'      => 'Staff Contract Beta',
            'file_path'  => $path,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // Download attempt
        $downloadRes = $this->actingAs($this->adminA)->get("/school/staff/documents/{$docB->id}/download");
        $this->assertTrue(in_array($downloadRes->status(), [403, 404]));

        // Delete attempt
        $deleteRes = $this->actingAs($this->adminA)->delete("/school/staff/documents/{$docB->id}");
        $this->assertTrue(in_array($deleteRes->status(), [403, 404]));
        $this->assertDatabaseHas('staff_documents', ['id' => $docB->id]);
        $this->assertTrue(Storage::disk('private')->exists($path));
    }

    /**
     * Test 7: Valid same-school student & staff document flows succeed.
     */
    public function test_same_school_document_download_and_delete_succeed(): void
    {
        // 1. Student Document
        $pathA = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'valid_student.pdf');
        Storage::disk('private')->put($pathA, 'Valid Student Content');

        $docStudentA = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Valid Student Report',
            'file_path'  => $pathA,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $downloadRes = $this->actingAs($this->adminA)->get("/school/students/documents/{$docStudentA->id}/download");
        $downloadRes->assertStatus(200);

        $deleteRes = $this->actingAs($this->adminA)->delete("/school/students/documents/{$docStudentA->id}");
        $deleteRes->assertStatus(302);
        $this->assertDatabaseMissing('student_documents', ['id' => $docStudentA->id]);
        $this->assertFalse(Storage::disk('private')->exists($pathA));

        // 2. Staff Document
        $pathStaffA = TenantStorage::staffDocumentPath($this->schoolA->id, $this->staffA->id, 'valid_staff.pdf');
        Storage::disk('private')->put($pathStaffA, 'Valid Staff Content');

        $docStaffA = StaffDocument::create([
            'school_id'  => $this->schoolA->id,
            'staff_id'   => $this->staffA->id,
            'title'      => 'Valid Staff Contract',
            'file_path'  => $pathStaffA,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $downloadStaffRes = $this->actingAs($this->adminA)->get("/school/staff/documents/{$docStaffA->id}/download");
        $downloadStaffRes->assertStatus(200);

        $deleteStaffRes = $this->actingAs($this->adminA)->delete("/school/staff/documents/{$docStaffA->id}");
        $deleteStaffRes->assertStatus(302);
        $this->assertDatabaseMissing('staff_documents', ['id' => $docStaffA->id]);
        $this->assertFalse(Storage::disk('private')->exists($pathStaffA));
    }

    /**
     * Test 8: Super Admin can download documents across schools.
     */
    public function test_super_admin_can_download_document_across_schools(): void
    {
        $pathB = TenantStorage::studentDocumentPath($this->schoolB->id, $this->studentB->id, 'super_accessible.pdf');
        Storage::disk('private')->put($pathB, 'Super Admin Access Content');

        $docB = StudentDocument::create([
            'school_id'  => $this->schoolB->id,
            'student_id' => $this->studentB->id,
            'title'      => 'Super Admin Access Report',
            'file_path'  => $pathB,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolB->id])
            ->get("/school/students/documents/{$docB->id}/download");
        $response->assertStatus(200);
    }

    /**
     * Test 9: Backward compatibility allows reading documents stored under legacy paths.
     */
    public function test_legacy_path_backward_compatibility(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/legacy_transcript.pdf";
        Storage::disk('private')->put($legacyPath, 'Legacy File Content');

        $legacyDoc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Legacy Transcript',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 2048,
        ]);

        // Attempt download -> TenantStorage falls back to legacy path and succeeds
        $response = $this->actingAs($this->adminA)->get("/school/students/documents/{$legacyDoc->id}/download");
        $response->assertStatus(200);
    }

    /**
     * Test 10: School branding logo and favicon are stored under schools/{school_id}/branding.
     */
    public function test_school_branding_upload_is_isolated_to_school_branding_path(): void
    {
        $logoFile = UploadedFile::fake()->image('school_logo.png', 200, 200);
        $faviconFile = UploadedFile::fake()->image('school_favicon.png', 32, 32);

        $response = $this->actingAs($this->adminA)->post('/school/settings/branding', [
            'logo'    => $logoFile,
            'favicon' => $faviconFile,
            'tagline' => 'Excellence in Education',
        ]);

        $response->assertStatus(302);

        $this->schoolA->refresh();
        $this->assertNotNull($this->schoolA->logo);

        $expectedBrandingDir = "schools/{$this->schoolA->id}/branding";
        $this->assertStringStartsWith($expectedBrandingDir, $this->schoolA->logo);
        $this->assertTrue(Storage::disk('public')->exists($this->schoolA->logo));
    }
}
