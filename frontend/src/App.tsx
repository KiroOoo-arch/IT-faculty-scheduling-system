import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import TermsAcceptanceGate from './components/TermsAcceptanceGate'
import LoginPage from './pages/LoginPage'
import PrivacyPolicyPage from './pages/PrivacyPolicyPage'
import TermsOfUsePage from './pages/TermsOfUsePage'
import AdminDashboard from './pages/AdminDashboard'
import PrintableSchedule from './pages/PrintableSchedule'
import PrintableFacultySchedule from './pages/PrintableFacultySchedule'
import RoomsPage from './pages/admin/RoomsPage'
import UsersPage from './pages/admin/UsersPage'
import FacultyPage from './pages/admin/FacultyPage'
import SubjectsPage from './pages/admin/SubjectsPage'
import SectionsPage from './pages/admin/SectionsPage'
import ReportsPage from './pages/admin/ReportsPage'           // ← ADD THIS LINE

function Dashboard() {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" replace />
  // Only admin accounts exist — faculty are records, not users.
  return <AdminDashboard />
}

/*
 * Signed-in pages.
 *
 * `requireTerms` wraps the page in the versioned Terms of Use prompt: an
 * account that has not accepted the current version is asked once, in place,
 * instead of being locked out of signing in. The two printable views opt out —
 * they are artifacts reached from inside an already-accepted session, and
 * interrupting a print with a policy prompt would be a worse trade for no
 * compliance gain.
 */
function ProtectedRoute({
  children,
  requireTerms = true,
}: {
  children: React.ReactNode
  requireTerms?: boolean
}) {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" replace />
  if (!requireTerms) return <>{children}</>
  return <TermsAcceptanceGate>{children}</TermsAcceptanceGate>
}

function AppRoutes() {
  const { user } = useAuth()
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/dashboard" replace /> : <LoginPage />} />
      {/* Public: the policies have to be readable before signing in, and they
          hold no data from the system. Declared as real routes so a direct
          visit or a browser refresh lands on the document itself. */}
      <Route path="/privacy" element={<PrivacyPolicyPage />} />
      <Route path="/terms" element={<TermsOfUsePage />} />
      <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
      <Route path="/admin/rooms" element={<ProtectedRoute><RoomsPage /></ProtectedRoute>} />
      <Route path="/admin/users" element={<ProtectedRoute><UsersPage /></ProtectedRoute>} />
      <Route path="/admin/faculty" element={<ProtectedRoute><FacultyPage /></ProtectedRoute>} />
      <Route path="/admin/subjects" element={<ProtectedRoute><SubjectsPage /></ProtectedRoute>} />
      <Route path="/admin/sections" element={<ProtectedRoute><SectionsPage /></ProtectedRoute>} />
      <Route path="/admin/reports" element={<ProtectedRoute><ReportsPage /></ProtectedRoute>} />   {/* ← ADD THIS LINE */}
      <Route path="/print-schedule" element={<ProtectedRoute requireTerms={false}><PrintableSchedule /></ProtectedRoute>} />
      <Route path="/print-faculty-schedule" element={<ProtectedRoute requireTerms={false}><PrintableFacultySchedule /></ProtectedRoute>} />
      <Route path="*" element={<Navigate to={user ? '/dashboard' : '/login'} replace />} />
    </Routes>
  )
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  )
}

export default App
