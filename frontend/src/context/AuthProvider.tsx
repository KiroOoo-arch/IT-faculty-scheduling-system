import { useCallback, useEffect, useState, type ReactNode } from 'react'
import {
  API_BASE_URL,
  AuthContext,
  clearStoredSession,
  redirectToLogin,
  type User,
} from './auth'

/**
 * The signed-in account and its token, shared with every page through `useAuth`.
 *
 * It lives in its own module, apart from the context object, the hook and the
 * fetch interceptor in `AuthContext.tsx`, because Vite's Fast Refresh only works
 * for a file that exports components — a file that also exports hooks and
 * constants is reloaded the slow way, and the components bound to it lose their
 * state on every edit.
 */
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
