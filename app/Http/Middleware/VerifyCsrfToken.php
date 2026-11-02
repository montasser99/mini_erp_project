<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;

/**
 * VerifyCsrfToken — Protection CSRF intelligente pour architecture hybride Web (Session) & API (Sanctum).
 *
 * Distinction de sécurité fondamentale :
 * 1. Authentification Stateless (Bearer Token / Sanctum) :
 *    Les navigateurs n'attachent JAMAIS automatiquement l'en-tête 'Authorization: Bearer <token>'
 *    lors de requêtes cross-origin. Une attaque CSRF est donc techniquement impossible sur ces flux.
 *    La vérification CSRF est donc exemptée en toute sécurité lorsque $request->bearerToken() est présent.
 *
 * 2. Authentification par Session / Cookies (Inertia.js / Web) :
 *    Les requêtes utilisant les cookies de session sont vulnérables aux attaques CSRF.
 *    La vérification stricte du jeton CSRF (X-XSRF-TOKEN ou champ _token) est OBLIGATOIREMENT appliquée.
 *    Toute tentative d'usurpation via session sans jeton CSRF valide est immédiatement rejetée avec HTTP 419.
 */
class VerifyCsrfToken extends BaseValidateCsrfToken
{
    /**
     * Traiter la requête entrante.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws \Illuminate\Session\TokenMismatchException
     */
    public function handle($request, Closure $next)
    {
        // Si la requête transporte un jeton Bearer Sanctum, CSRF est exempté de manière sécurisée
        if ($request->bearerToken() !== null) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

