import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from 'react'

const API_BASE_URL = 'http://127.0.0.1:8000/api'

type User = {
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

const AuthContext = createContext<AuthContextType | null>(null)

// Clear the persisted session; on an expired token a hard redirect to /login
// follows, so no page lingers showing empty tables as though the database were
// empty.
function clearStoredSession() {
  localStorage.removeItem('token')
  localStorage.removeItem('user')
}

function redirectToLogin() {
  if (window.location.pathname !== '/login') {
    window.location.assign('/login')
  }
}

// Centralized token-expiry handling. Every API call in the app goes through
// `window.fetch`, so one interceptor covers all of them instead of each page
// special-casing 401. It is installed at module load — before React mounts —
// because child page effects run before the provider's effect, so a
// provider-scoped install would miss the very first fetch after a hard reload.
// The response is returned untouched, so callers keep normal 401 behavior.
function installSessionExpiryInterceptor() {
  if (typeof window === 'undefined') return

  // Guard on the window so a dev-server hot reload cannot stack interceptors.
  const global = window as typeof window & { __authInterceptorInstalled?: boolean }
  if (global.__authInterceptorInstalled) return
  global.__authInterceptorInstalled = true

  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const response = await originalFetch(input, init)

    if (response.status === 401) {
      const url =
        typeof input === 'string'
          ? input
          : input instanceof Request
            ? input.url
            : String(input)

      // The login endpoints answer 401 for "not signed in"; those must not
      // wipe the session or trigger a redirect loop.
      if (!url.includes('/login')) {
        clearStoredSession()
        redirectToLogin()
      }
    }

    return response
  }
}

installSessionExpiryInterceptor()

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(localStorage.getItem('token'))
  const [user, setUser] = useState<User | null>(() => {
    const saved = localStorage.getItem('user')
    return saved ? JSON.parse(saved) : null
  })

  // The cached profile in localStorage is written once at login, so renaming an
  // account (or changing its role) would not show up until the next sign-in —
  // the header would keep greeting the old name. Re-read it once per load so it
  // always reflects the account as it is now. Failures are ignored: the fetch
  // interceptor already handles an expired token, and a transient error must
  // not sign anyone out.
  useEffect(() => {
    if (!token) return
    let cancelled = false
    fetch(`${API_BASE_URL}/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
      .then((res) => (res.ok ? res.json() : null))
      .then((me) => {
        if (cancelled || !me) return
        setUser(me)
        localStorage.setItem('user', JSON.stringify(me))
      })
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [token])

  // Used by the logout button: also clears the in-memory React state.
  const clearSession = useCallback(() => {
    clearStoredSession()
    setToken(null)
    setUser(null)
    redirectToLogin()
  }, [])

  async function login(email: string, password: string) {
    const response = await fetch(`${API_BASE_URL}/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ email, password }),
    })

    if (!response.ok) {
      const data = await response.json()
      throw new Error(data.message || 'Login failed')
    }

    const data = await response.json()
    localStorage.setItem('token', data.token)
    localStorage.setItem('user', JSON.stringify(data.user))
    setToken(data.token)
    setUser(data.user)
  }

  function logout() {
    clearSession()
  }

  return (
    <AuthContext.Provider value={{ user, token, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}

export { API_BASE_URL }