<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Framework\Routing\Attributes\Route;
use Framework\Routing\Controller;
use WP_REST_Request;
use WP_REST_Response;

class CompanyController extends Controller
{
    #[Route('GET', 'Company')]
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond([]);
    }

    #[Route('POST', 'Company')]
    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->created([]);
    }

    #[Route('GET', 'Company/(?P<id>\d+)')]
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['id' => $request->get_param('id')]);
    }

    #[Route('PUT', 'Company/(?P<id>\d+)')]
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['id' => $request->get_param('id')]);
    }

    #[Route('DELETE', 'Company/(?P<id>\d+)')]
    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        return $this->noContent();
    }
}
