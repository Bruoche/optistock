// Small same-origin JSON POST helper for the app's API endpoints. Sends the
// Laravel CSRF token (read from the XSRF-TOKEN cookie) so session-cookie auth
// accepts the request.

function readCookie(name: string): string | null {
    const match = document.cookie.match(
        new RegExp('(^|;\\s*)' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[2]) : null;
}

export async function postJson(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
        },
        body: JSON.stringify(body),
    });
}

// Multipart POST for endpoints that accept a file upload (e.g. a driver's image). The
// caller sets the method via a `_method` field for Laravel form-method spoofing; no
// Content-Type is set so the browser adds the multipart boundary itself.
export async function postForm(url: string, body: FormData): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
        },
        body,
    });
}
