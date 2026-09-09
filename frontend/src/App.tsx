import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import LoginPage from './pages/LoginPage'
import AdminDashboard from './pages/AdminDashboard'
import PrintableSchedule from './pages/PrintableSchedule'
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

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" replace />
  return <>{children}</>
}

function AppRoutes() {
  const { user } = useAuth()
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/dashboard" replace /> : <LoginPage />} />
      <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
      <Route path="/admin/rooms" element={<ProtectedRoute><RoomsPage /></ProtectedRoute>} />
      <Route path="/admin/users" element={<ProtectedRoute><UsersPage /></ProtectedRoute>} />
      <Route path="/admin/faculty" element={<ProtectedRoute><FacultyPage /></ProtectedRoute>} />
      <Route path="/admin/subjects" element={<ProtectedRoute><SubjectsPage /></ProtectedRoute>} />
      <Route path="/admin/sections" element={<ProtectedRoute><SectionsPage /></ProtectedRoute>} />
      <Route path="/admin/reports" element={<ProtectedRoute><ReportsPage /></ProtectedRoute>} />   {/* ← ADD THIS LINE */}
      <Route path="/print-schedule" element={<ProtectedRoute><PrintableSchedule /></ProtectedRoute>} />
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
