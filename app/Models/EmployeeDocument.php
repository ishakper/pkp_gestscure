<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_number',
        'employee_id',
        'internship_id',
        'contract_id',
        'category',
        'title',
        'description',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'checksum',
        'version',
        'parent_document_id',
        'status',
        'visibility',
        'issue_date',
        'expiry_date',
        'issuer',
        'uploaded_by',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function parentDocument() { return $this->belongsTo(self::class, 'parent_document_id'); }
    public function versions() { return $this->hasMany(self::class, 'parent_document_id')->orderBy('version', 'desc'); }
    public function uploadedByAdmin() { return $this->belongsTo(Admin::class, 'uploaded_by'); }
    public function verifiedByAdmin() { return $this->belongsTo(Admin::class, 'verified_by'); }
    public function acknowledgements() { return $this->hasMany(DocumentAcknowledgement::class, 'document_id'); }
}
