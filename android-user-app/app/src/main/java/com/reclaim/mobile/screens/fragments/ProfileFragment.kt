package com.reclaim.mobile.screens.fragments

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.ProgressBar
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.auth.AuthRepository
import com.reclaim.mobile.models.ProfileEnvelope
import com.reclaim.mobile.screens.activities.AuthActivity
import kotlinx.coroutines.launch

class ProfileFragment : Fragment() {
    private lateinit var repository: MobileRepository
    private lateinit var authRepository: AuthRepository
    private var departments: List<String> = emptyList()
    private var currentProfile: ProfileEnvelope? = null

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_profile, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        repository = MobileRepository(requireContext())
        authRepository = AuthRepository(requireContext())

        val header: TextView = view.findViewById(R.id.textProfileHeader)
        val stats: TextView = view.findViewById(R.id.textProfileStats)
        val name: TextInputEditText = view.findViewById(R.id.editProfileName)
        val phone: TextInputEditText = view.findViewById(R.id.editProfilePhone)
        val department: Spinner = view.findViewById(R.id.spinnerProfileDepartment)
        val studentId: TextInputEditText = view.findViewById(R.id.editProfileStudentId)
        val currentPassword: TextInputEditText = view.findViewById(R.id.editProfileCurrentPassword)
        val newPassword: TextInputEditText = view.findViewById(R.id.editProfileNewPassword)
        val errorText: TextView = view.findViewById(R.id.textProfileError)
        val progress: ProgressBar = view.findViewById(R.id.progressProfile)
        val saveButton: MaterialButton = view.findViewById(R.id.buttonSaveProfile)
        val logoutButton: MaterialButton = view.findViewById(R.id.buttonLogout)

        fun bindProfile(profile: ProfileEnvelope) {
            currentProfile = profile
            header.text = profile.user.name
            stats.text = "Reports: ${profile.stats?.totalReports ?: 0} • Claims: ${profile.stats?.totalClaims ?: 0} • Approved: ${profile.stats?.approvedClaims ?: 0}"
            name.setText(profile.user.name)
            phone.setText(profile.user.phone)
            studentId.setText(profile.user.studentStaffId)

            if (departments.isNotEmpty()) {
                val index = departments.indexOf(profile.user.department).takeIf { it >= 0 } ?: 0
                department.setSelection(index)
            }
        }

        fun loadProfile() {
            progress.visibility = View.VISIBLE
            lifecycleScope.launch {
                repository.profile()
                    .onSuccess { bindProfile(it) }
                    .onFailure {
                        errorText.visibility = View.VISIBLE
                        errorText.text = ApiErrorParser.message(it)
                    }
                progress.visibility = View.GONE
            }
        }

        lifecycleScope.launch {
            repository.options()
                .onSuccess { options ->
                    departments = options.departments
                    department.adapter = ArrayAdapter(requireContext(), android.R.layout.simple_spinner_dropdown_item, departments)
                    currentProfile?.let(::bindProfile)
                }
                .onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }
        }

        saveButton.setOnClickListener {
            errorText.visibility = View.GONE
            progress.visibility = View.VISIBLE
            lifecycleScope.launch {
                repository.updateProfile(
                    name = name.text?.toString()?.trim().orEmpty(),
                    phone = phone.text?.toString()?.trim().orEmpty(),
                    department = department.selectedItem?.toString().orEmpty(),
                    studentStaffId = studentId.text?.toString()?.trim().orEmpty(),
                    currentPassword = currentPassword.text?.toString().orEmpty(),
                    newPassword = newPassword.text?.toString().orEmpty()
                ).onSuccess {
                    Toast.makeText(requireContext(), "Profile updated successfully.", Toast.LENGTH_LONG).show()
                    currentPassword.setText("")
                    newPassword.setText("")
                    bindProfile(it)
                }.onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }
                progress.visibility = View.GONE
            }
        }

        logoutButton.setOnClickListener {
            lifecycleScope.launch {
                authRepository.logout()
                startActivity(Intent(requireContext(), AuthActivity::class.java))
                requireActivity().finish()
            }
        }

        loadProfile()
    }
}
