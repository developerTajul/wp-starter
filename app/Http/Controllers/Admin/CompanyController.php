<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

final class CompanyController
{
    public function index(): ResponseInterface
    {
        return view('company.index', [
            'companies' => [],
        ]);
    }

    public function create(): ResponseInterface
    {
        return view('company.create', [
            'company' => null,
        ]);
    }

    public function store(Request $request): ResponseInterface
    {
        return redirect()->route('company.index')->with('success', 'Created.');
    }

    public function show(string $id): ResponseInterface
    {
        return view('company.show', [
            'company' => ['id' => $id],
        ]);
    }

    public function edit(string $id): ResponseInterface
    {
        return view('company.edit', [
            'company' => ['id' => $id],
        ]);
    }

    public function update(Request $request, string $id): ResponseInterface
    {
        return redirect()->route('company.index')->with('success', 'Updated.');
    }

    public function destroy(string $id): ResponseInterface
    {
        return redirect()->route('company.index')->with('success', 'Deleted.');
    }
}
