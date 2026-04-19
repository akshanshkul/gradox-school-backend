<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Session;
use App\Models\FeeType;
use App\Models\FeeAssignment;
use App\Models\FeeInstallment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateFeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fees:generate {--school=} {--date=}';

    /**
     * The console command description.
     */
    protected $description = 'Generate recurring fee installments for students';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting fee generation at " . now()->toDateTimeString());
        
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        
        $schoolsQuery = School::query();
        if ($this->option('school')) {
            $schoolsQuery->where('id', $this->option('school'));
        }
        
        $schools = $schoolsQuery->get();

        foreach ($schools as $school) {
            $this->info("Processing school: {$school->name} (ID: {$school->id})");
            $this->processSchool($school, $date);
        }
        
        $this->info("Fee generation completed.");
    }

    private function processSchool($school, $date)
    {
        $session = Session::where('school_id', $school->id)->where('is_active', true)->first();
        if (!$session) {
            $this->warn("No active session found for school ID {$school->id}. Skipping.");
            return;
        }

        // Get all active recurring assignments for this school/session
        $assignments = FeeAssignment::where('school_id', $school->id)
            ->where('session_id', $session->id)
            ->with(['feeType', 'student'])
            ->get();

        foreach ($assignments as $assignment) {
            $this->processAssignment($assignment, $date);
        }
    }

    private function processAssignment($assignment, $date)
    {
        $type = $assignment->feeType;
        if (!$type->is_active) return;

        // Logic check: should we generate an installment for this assignment on this date?
        // For simplicity: If monthly, check if an installment for this month/year already exists.
        
        $installmentNo = $this->calculateInstallmentNo($type->frequency_type, $date);
        if ($installmentNo === null) return;

        $exists = FeeInstallment::where('fee_assignment_id', $assignment->id)
            ->where('installment_no', $installmentNo)
            ->exists();

        if (!$exists) {
            $dueDate = $this->calculateDueDate($assignment, $date);
            
            FeeInstallment::create([
                'fee_assignment_id' => $assignment->id,
                'installment_no' => $installmentNo,
                'amount' => $assignment->amount,
                'due_date' => $dueDate,
                'status' => 'unpaid'
            ]);
            
            $this->line("Created installment {$installmentNo} for Assignment ID {$assignment->id}");
        }
    }

    private function calculateInstallmentNo($frequency, $date)
    {
        switch ($frequency) {
            case 'monthly':
                return (int) $date->format('Ym'); // e.g., 202404
            case 'yearly':
                return (int) $date->format('Y');
            default:
                return null;
        }
    }

    private function calculateDueDate($assignment, $date)
    {
        if ($assignment->due_date) return $assignment->due_date;
        
        $day = $assignment->due_day ?? 10; // Default to 10th of the month
        return $date->copy()->day($day);
    }
}
