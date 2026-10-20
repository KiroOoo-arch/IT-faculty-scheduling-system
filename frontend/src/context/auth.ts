import { createContext, useContext } from 'react'

/**
 * The shared session module: the context object, its hook, the API base URL
 * and the fetch interceptor that centralizes token expiry. The provider
 * component itself lives in `AuthProvider.tsx`, because Vite's Fast Refresh
 * only works for a file that exports components and nothing else — this
 * module deliberately exports no component.
 */const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api'

export type User = {
  id: number
  name: string
  email: string
  role: string
}

type AuthContextType = {
  user: User | null
  token: string | null
  login: (email: string, password: string) => Promise<void>
  logout: () => void
}

export const AuthContext = createContext<AuthContextType | null>(null)

// Clear the persisted session; on an expired token a hard redirect to /login
// follows, so no page lingers showing empty tables as though the database were
// empty.
export function clearStoredSession() {
  localStorage.removeItem("token")
  localStorage.removeItem("user")
}

export function redirectToLogin() {
  if (window.location.pathname !== "/login") {
    window.location.assign("/login")
  }
}

// Centralized token-expiry handling. Every API call in the app goes through
// `window.fetch`, so one interceptor covers all of them instead of each page
// special-casing 401. It is installed at module load — before React mounts —
// because child page effects run before the provider's effect, so a
// provider-scoped install would miss the very first fetch after a hard reload.
// The response is returned untouched, so callers keep normal 401 behavior.
function installSessionExpiryInterceptor() {
  if (typeof window === "undefined") return

  // Guard on the window so a dev-server hot reload cannot stack interceptors.
  const global = window as typeof window & { __authInterceptorInstalled?: boolean }
  if (global.__authInterceptorInstalled) return
  global.__authInterceptorInstalled = true

  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const response = await originalFetch(input, init)

    if (response.status === 401) {
      const url =
        typeof input === "string"
          ? input
          : input instanceof Request
            ? input.url
            : String(input)

      // The login endpoints answer 401 for "not signed in"; those must not
      // wipe the session or trigger a redirect loop.
      if (!url.includes("/login")) {
        clearStoredSession()
        redirectToLogin()
      }
    }

    return response
  }
}

installSessionExpiryInterceptor()

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error("useAuth must be used within AuthProvider")
  return ctx
}

export { API_BASE_URL }
