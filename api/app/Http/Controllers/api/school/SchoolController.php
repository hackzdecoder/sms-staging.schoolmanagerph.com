<?php

namespace App\Http\Controllers\api\school;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SchoolController extends Controller
{
    public function getSchool() {
        // Fetch all schools from idrs_school connection
        $schools = DB::connection('idrs_school')
            ->table('school_id')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $schools
        ]);
    }
}
